# YMR YSMS — Flutter app (Android and iOS)

Two apps are built from this code (GitHub Actions → "Flutter app"):

| App | App id | Name | Server |
|---|---|---|---|
| staging | `com.ymrmarine.ysms.staging` | YSMS Staging | staging server and its data |
| live | `com.ymrmarine.ysms` | YMR YSMS | live server (installs over the earlier live test APK) |

The server address is in `lib/config.dart`; `--dart-define=YSMS_SERVER=https://…/YSMS/` overrides it.

## Screens

Made in Flutter: login (fingerprint login too), forgot password, home dashboard, Pending Vessels /
Pending Reports / Completed / Cancelled lists, search, vessel details (latest update, documents and
replace, recovery edit, remarks, agent email, share, history), upload, profile and My profile, team chat,
inbox, recovery, formats, assign / edit vessel, manage templates, add client / admin / surveyor, admin
controls (master data, duplicates, login activity, data cleanup), surveyor cards, daily emails, permission
copy.

Survey Report (staging app only for now, `lib/sr/`): the website's survey report editor in Flutter — report
types, vessel lookup by IMO, survey details, tank cards with S / U and the Table 54B results (the same
numbers as the website), the ROB comparison checked on the server, the PDF made on the server. It works
without internet: reports are kept on the phone and sent when online, a report can be started offline,
and a PDF can be made on the phone (the final one is made by the server once the connection is back).

The report generators (Word, photo, invoice, survey reports) open the website's own pages inside the
app (`lib/screens/web.dart`), signed in with the same cookies.

## How it talks to the server

Read-only JSON from `app_api.php` (the same rules as the website); every change goes through the
existing pages (`login.php`, `vessel_detail.php`, `ajax/upload_handler.php` …), so the server's own
permission checks, CSRF checks and notifications apply unchanged. No server change is needed.

## Build locally

```
flutter pub get
flutter test
flutter build apk --release --flavor staging      # or --flavor live
flutter build ios --release --no-codesign --dart-define=YSMS_FLAVOR=live
```

Android test builds are signed with `android/app/test-debug.keystore` (test key only; the Play Store
key is never kept in the repository). iOS builds need an Apple Developer account to be signed.
