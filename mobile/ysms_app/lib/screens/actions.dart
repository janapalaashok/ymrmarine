// Shortcuts to every tool this role can use (Home quick actions and the Profile menu). Tools that have a
// Flutter screen open it; the rest open the web app's screen or the website page inside the app.
import 'package:flutter/material.dart';

import '../api.dart';
import '../config.dart';
import '../ui.dart';
import 'lists.dart';

class AppAction {
  const AppAction(this.key, this.label, this.icon, this.color, this.to);
  final String key, label, to; // to: '#route' (web app screen), 'page.php' (website page), '!native'
  final IconData icon;
  final Color color;
}

List<AppAction> allActions(Map<String, dynamic> me) {
  final can = (me['can'] as Map?) ?? {};
  final role = s(me['role']);
  bool c(String k) => can[k] == true;
  final admin = role == 'Admin', sa = role == 'Super Admin';
  return [
    if (c('assign')) AppAction('assign', role == 'Client' ? 'Request survey' : 'Assign vessel', Icons.add_rounded, blue, '#/assign'),
    if (c('report_generator')) const AppAction('word', 'Word report', Icons.description_outlined, orange, 'report_generator.php'),
    if (c('survey_reports')) const AppAction('surveyreports', 'Survey reports', Icons.fact_check_outlined, green, 'survey_reports.php'),
    // the survey report made in Flutter (offline too) — staging app only for now
    if (!Cfg.isLive && role != 'Client')
      const AppAction('srnative', 'Survey Report', Icons.edit_note_rounded, sky, 'survey_report_public.php'),
    if (c('live_survey')) const AppAction('livesr', 'Live survey reports', Icons.sensors_rounded, red, 'live_survey_reports.php'),
    if (c('recovery')) const AppAction('recovery', 'Recovery', Icons.local_gas_station_outlined, sky, '#/recovery'),
    if (c('chat')) const AppAction('chat', 'Team chat', Icons.forum_outlined, purple, '#/chat'),
    if (c('inbox')) const AppAction('inbox', 'Inbox', Icons.inbox_outlined, blue, '#/inbox'),
    const AppAction('search', 'Search', Icons.search_rounded, blue, '!search'),
    if (role != 'Client') const AppAction('formats', 'Formats', Icons.folder_outlined, green, '#/formats'),
    const AppAction('cancelled', 'Cancelled', Icons.block_rounded, red, '!cancelled'),
    const AppAction('permission', 'Permission copy', Icons.note_alt_outlined, blue, 'generate_permission_copy.php'),
    if (role == 'Surveyor')
      const AppAction('lineups', 'Vessel line-ups', Icons.directions_boat_outlined, sky, 'coming_soon.php?feature=Vessel+Lineups'),
    if (admin) const AppAction('surveyor', 'Add surveyor', Icons.person_add_alt_outlined, orange, '#/surveyor'),
    if (admin || sa) const AppAction('addclient', 'Add client', Icons.group_add_outlined, green, '#/addclient'),
    if (sa) const AppAction('addadmin', 'Add admin', Icons.admin_panel_settings_outlined, purple, '#/addadmin'),
    if (admin) const AppAction('admin', 'Admin controls', Icons.shield_outlined, purple, '#/admin'),
    if (sa) const AppAction('admin', 'Manage clients', Icons.groups_outlined, blue, '#/admin'),
    if (sa) const AppAction('sacontrols', 'Data cleanup', Icons.cleaning_services_outlined, red, '#/sacontrols'),
    if (admin || sa) const AppAction('activity', 'Login activity', Icons.security_rounded, red, '#/activity'),
    if (admin) const AppAction('duplicates', 'Possible duplicates', Icons.copy_all_outlined, orange, '#/duplicates'),
    if (admin) const AppAction('templates', 'Templates', Icons.article_outlined, blue, '#/templates'),
    if (c('report_templates'))
      const AppAction('rtemplates', 'Report templates', Icons.library_books_outlined, purple, 'admin_report_templates.php'),
    const AppAction('profile', 'My profile', Icons.badge_outlined, blue, '#/me'),
  ];
}

Future<void> runAction(BuildContext context, AppAction a) async {
  if (a.to == '!search') {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SearchScreen()));
  } else if (a.to == '!cancelled') {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ListScreen(kind: ListKind.cancelled, pushed: true)));
  } else if (a.to.startsWith('#')) {
    await openAppRoute(context, a.to.substring(1));
  } else {
    await openWeb(context, a.to);
  }
  // counts (inbox unread …) may have changed there
  try {
    await Api.i.loadMe();
  } catch (_) {}
}
