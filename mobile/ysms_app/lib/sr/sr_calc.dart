// Survey report calculation (ASTM Table 54B) — a port of assets/js/report-calc.js: the same steps and
// rounding as the report template's own TABLES 54B formulas. The constants and decimals come from the
// template (the report's config `calc`); without them nothing is calculated (the server always makes
// the files from the template's formulas).
//   ρ    = ROUND(density / 2 × 1000, 0) × 2
//   α    = ROUND(K0 / ρ² + K1 / ρ, alpha_dp)
//   VCF  = ROUND(EXP(−α·Δt·(1 + 0.8·α·Δt)), vcf_dp)   Δt = temp − base
//   GSV  = ROUND(volume × VCF, gsv_dp)
//   MT   = ROUND(GSV × (density − wcf), mt_dp)
import 'dart:math' as math;

/// Excel ROUND: half away from zero, on the decimal value (not the binary float) — as the JS version.
double xround(double v, int d) {
  if (!v.isFinite) return double.nan;
  final s = v < 0 ? -1 : 1;
  final a = v.abs();
  final shifted = double.parse('${_plain(a)}e$d');
  final r = double.parse('${shifted.round()}e-$d');
  return s * r;
}

String _plain(double a) => a == a.roundToDouble() && a.abs() < 1e15 ? a.toStringAsFixed(0) : a.toString();

/// A typed number ("1,234.5" → 1234.5), null when empty or not a number.
double? srNum(Object? v) {
  if (v == null) return null;
  final t = '$v'.replaceAll(',', '').trim();
  if (t.isEmpty) return null;
  return double.tryParse(t);
}

class SrCalc {
  const SrCalc({
    required this.k0,
    required this.k1,
    required this.wcf,
    required this.base,
    required this.alphaDp,
    required this.vcfDp,
    required this.gsvDp,
    required this.mtDp,
  });
  final double k0, k1, wcf, base;
  final int alphaDp, vcfDp, gsvDp, mtDp;

  static SrCalc? fromMap(Object? m) {
    if (m is! Map) return null;
    double d(String k) => (m[k] as num?)?.toDouble() ?? 0;
    int i(String k) => (m[k] as num?)?.toInt() ?? 3;
    return SrCalc(
      k0: d('k0'),
      k1: d('k1'),
      wcf: d('wcf'),
      base: d('base'),
      alphaDp: i('alpha_dp'),
      vcfDp: i('vcf_dp'),
      gsvDp: i('gsv_dp'),
      mtDp: i('mt_dp'),
    );
  }
}

class TankResult {
  const TankResult({this.vcf, this.gsv, this.mt, this.wcf});
  final double? vcf, gsv, mt, wcf;
}

/// One tank: V.C.F., G.S.V. and MT; W.C.F. = density − wcf (4 decimals). No temperature → no result.
TankResult tankCalc(Map row, SrCalc? c) {
  final d = srNum(row['density']);
  final wcf = d == null || c == null ? null : xround(d - c.wcf, 4);
  if (c == null) return TankResult(wcf: wcf);
  final temp = srNum(row['temp']);
  if (temp == null) return TankResult(wcf: wcf);
  final f = d ?? 0, e = srNum(row['volume']) ?? 0;
  final rho = xround(f / 2 * 1000, 0) * 2;
  if (rho == 0) return TankResult(wcf: wcf);
  final alpha = xround(c.k0 / (rho * rho) + (c.k1 / rho), c.alphaDp);
  final dt = temp - c.base;
  final vcf = xround(math.exp(-alpha * dt * (1 + 0.8 * alpha * dt)), c.vcfDp);
  final gsv = xround(e * vcf, c.gsvDp);
  final mt = xround(gsv * (f - c.wcf), c.mtDp);
  return TankResult(vcf: vcf, gsv: gsv, mt: mt, wcf: wcf);
}

/// 3 decimals ("" for none), and with thousands separators.
String f3(double? v) => v == null || v.isNaN ? '' : v.toStringAsFixed(3);
String f3c(double? v) {
  if (v == null || v.isNaN) return '';
  final neg = v < 0;
  final p = v.abs().toStringAsFixed(3).split('.');
  final i = p[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
  return '${neg ? '-' : ''}$i.${p[1]}';
}

/// Signed difference: +1.234 / −1.234 / 0.000, "—" for none.
String sgn(double? v) => v == null || v.isNaN
    ? '—'
    : '${v > 0
          ? '+'
          : v < 0
          ? '−'
          : ''}${v.abs().toStringAsFixed(3)}';
