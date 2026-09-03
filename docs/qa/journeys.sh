#!/usr/bin/env bash
# End-to-end journeys through real HTTP against a clean PostgreSQL database.
# Every step asserts a status code; any deviation is printed and counted.
set -uo pipefail

# Point this at a SEEDED DEV database only. It signs in as the seeded accounts
# and mutates data. Override the credentials rather than editing the file.
B="${CICTO_BASE_URL:-http://127.0.0.1:8100}"

# The dev server was started against cicto_e2e; the inline PHP below bootstraps
# Laravel itself and would otherwise read .env and hit the DEV database, so the
# ids it returns would not exist on the server. Same database, both sides.
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=cicto_e2e
export DB_USERNAME="${CICTO_DB_USER:-cicto}"
export DB_PASSWORD="${CICTO_DB_PASSWORD:-$(cat /tmp/cicto-pw 2>/dev/null)}"
PASS=0
FAIL=0

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

# expect <label> <expected> <actual>
expect() {
  if [ "$2" = "$3" ]; then ok "$1 ($3)"; else bad "$1 — expected $2, got $3"; fi
}

jar_for() { echo "/tmp/cicto-e2e-$1.jar"; }

# login <who> <email> -> populates a cookie jar
login() {
  local jar; jar=$(jar_for "$1")
  rm -f "$jar"
  curl -s -c "$jar" "$B/login" >/dev/null
  local xsrf
  xsrf=$(php -r '$c=file_get_contents($argv[1]); if (preg_match("/XSRF-TOKEN\s+(\S+)/",$c,$m)) echo urldecode($m[1]);' "$jar")
  local code
  code=$(curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/login" \
    -H "X-XSRF-TOKEN: $xsrf" -H "X-Requested-With: XMLHttpRequest" -H "X-Inertia: true" \
    --data-urlencode "email=$2" --data-urlencode "password=${CICTO_SEED_PASSWORD:-password}")
  echo "$code"
}

# get <who> <path>
get() { curl -s -b "$(jar_for "$1")" -o /dev/null -w '%{http_code}' "$B$2"; }

# lands <who> <path> -> where a redirect actually points. "" for anyone means a
# guest with no cookie jar, which is how a bookmark arrives.
lands() {
  if [ -n "$1" ]; then
    curl -s -b "$(jar_for "$1")" -o /dev/null -w '%{redirect_url}' "$B$2"
  else
    curl -s -o /dev/null -w '%{redirect_url}' "$B$2"
  fi
}

# post <who> <path> [curl args...]
post() {
  local who=$1 path=$2; shift 2
  local jar; jar=$(jar_for "$who")
  local xsrf
  xsrf=$(php -r '$c=file_get_contents($argv[1]); if (preg_match("/XSRF-TOKEN\s+(\S+)/",$c,$m)) echo urldecode($m[1]);' "$jar")
  curl -s -b "$jar" -c "$jar" -o /tmp/cicto-e2e-body.html -w '%{http_code}' -X POST "$B$path" \
    -H "X-XSRF-TOKEN: $xsrf" -H "X-Requested-With: XMLHttpRequest" -H "X-Inertia: true" \
    -H "X-Inertia-Version: x" "$@"
}

props() {
  curl -s -b "$(jar_for "$1")" "$B$2" \
    | python3 -c "
import sys,json,re
raw = sys.stdin.read()
m = re.search(r'data-page=\"app\" type=\"application/json\">(.*?)</script>', raw, re.S)
print(json.dumps(json.loads(m.group(1))['props']) if m else '{}')
"
}

say "1. Authentication — one login screen, three roles"
expect "clerk signs in"       302 "$(login clerk clerk@cicto.test)"
expect "admin signs in"       302 "$(login admin admin@cicto.test)"
expect "super admin signs in" 302 "$(login super super@cicto.test)"
# The role portals were removed on 2026-08-17; their URLs now redirect to /login.
# Assert the TARGET, not just "a 302" -- the old portal routes answered 302 too,
# by bouncing a signed-in visitor off a login page, so a bare status check here
# passes just as happily against the behaviour this replaced.
expect "/login/admin redirects to the single login screen" "$B/login" "$(lands admin /login/admin)"
# And from a bookmark, with no session at all, which is the case that matters.
expect "a bookmarked /login/super-admin lands there too" "$B/login" "$(lands '' /login/super-admin)"
# A POST with no CSRF token is a 419, which is the framework working.
expect "CSRF-less login POST refused"      419 "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/login" --data-urlencode 'email=admin@cicto.test' --data-urlencode 'password=wrong')"

say "2. Role separation — each panel refuses the wrong role"
expect "clerk blocked from admin panel"       403 "$(get clerk /admin/dashboard)"
expect "clerk blocked from super admin panel" 403 "$(get clerk /super-admin/dashboard)"
expect "admin blocked from super admin panel" 403 "$(get admin /super-admin/dashboard)"
# Client question B3's module. Setting somebody's password is a complete account
# takeover, so the refusal for the wrong role matters more here than on a page.
# User id 1 is whatever the seed made first; the role check fires before the
# route model binding, so the id only has to be numeric.
expect "clerk cannot set another account's password" 403 "$(post clerk /super-admin/users/1/password --data-urlencode 'password=x')"
expect "admin cannot set another account's password" 403 "$(post admin /super-admin/users/1/password --data-urlencode 'password=x')"
expect "admin reaches admin panel"            200 "$(get admin /admin/dashboard)"
expect "super admin reaches both"             200 "$(get super /super-admin/dashboard)"

say "3. Every authenticated page renders"
for p in /dashboard /documents /documents/create /documents/scan /reports /help \
         /help/knowledge-base /help/contact /help/ticket /archive /notifications \
         /settings/profile /settings/appearance /privacy; do
  expect "GET $p" 200 "$(get admin "$p")"
done
# Password confirmation guards the security page -- a 302 here is the point.
expect "GET /settings/security asks to confirm password" 302 "$(get admin /settings/security)"
# B3: with no mailer configured this page must NOT offer a form that claims to
# send a link. It still has to render -- the warning it shows is the answer.
expect "forgot password renders without a mailer" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$B/forgot-password")"

say "4. Public routes need no session"
expect "privacy notice"        200 "$(curl -s -o /dev/null -w '%{http_code}' "$B/privacy")"
expect "unknown scan token"    200 "$(curl -s -o /dev/null -w '%{http_code}' "$B/s/NOSUCHTOKENAAAAAAAAAAAAAA")"
expect "unknown signature"     200 "$(curl -s -o /dev/null -w '%{http_code}' "$B/verify/nosuchserial")"

say "5. Bad input is refused, not crashed"
expect "non-numeric document id"  404 "$(get admin /documents/archived)"
expect "missing document"         404 "$(get admin /documents/999999)"
expect "invalid sort rejected"    302 "$(get admin '/documents?sort=password')"
expect "invalid per_page"         302 "$(get admin '/documents?per_page=9999')"
expect "unknown help article"     404 "$(get admin /help/knowledge-base/nope)"
expect "unknown route"            404 "$(get admin /no/such/page)"

say "6. Full document lifecycle"
DOCID=$(php -r '
$_SERVER["argv"]=[];
require "/Users/joshuagencianeo/Documents/GitHub/CICTO/vendor/autoload.php";
$app = require "/Users/joshuagencianeo/Documents/GitHub/CICTO/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$o = App\Models\Office::first();
$c = App\Models\User::firstWhere("email","clerk@cicto.test");
$c->forceFill(["office_id"=>$o->id])->save();
App\Models\User::firstWhere("email","admin@cicto.test")->forceFill(["office_id"=>$o->id])->save();
$d = app(App\Actions\Documents\RegisterDocument::class)->handle(
  title: "E2E lifecycle", documentTypeId: App\Models\DocumentType::first()->id,
  priority: App\Enums\DocumentPriority::Normal, originatingOffice: $o, creator: $c,
  upload: Illuminate\Http\UploadedFile::fake()->createWithContent("e2e.pdf","%PDF-1.4 e2e"),
);
echo $d->id;
' 2>/dev/null)
expect "document registered"            1 "$([ -n "$DOCID" ] && echo 1 || echo 0)"
expect "clerk can view it"            200 "$(get clerk "/documents/$DOCID")"
expect "admin can view it"            200 "$(get admin "/documents/$DOCID")"

LEG=$(props admin "/documents/$DOCID" | python3 -c "import sys,json; print(json.load(sys.stdin)['document']['expected_movement_id'])")
expect "received"                     302 "$(post admin "/documents/$DOCID/transitions" --data-urlencode "action=received" --data-urlencode "expected_movement_id=$LEG")"

LEG=$(props admin "/documents/$DOCID" | python3 -c "import sys,json; print(json.load(sys.stdin)['document']['expected_movement_id'])")
expect "stale leg id is refused"      409 "$(post admin "/documents/$DOCID/transitions" --data-urlencode "action=received" --data-urlencode "expected_movement_id=999999")"

# Approval was removed on 2026-09-03 -- offices press Received and the folder
# moves on by itself. A hand-rolled 'approved' must now bounce, not succeed.
expect "approving is refused"         403 "$(post admin "/documents/$DOCID/transitions" --data-urlencode "action=approved" --data-urlencode "remarks=nope" --data-urlencode "expected_movement_id=$LEG")"

LEG=$(props admin "/documents/$DOCID" | python3 -c "import sys,json; print(json.load(sys.stdin)['document']['expected_movement_id'])")
expect "completed"                    302 "$(post admin "/documents/$DOCID/transitions" --data-urlencode "action=completed" --data-urlencode "expected_movement_id=$LEG")"

STATUS=$(props admin "/documents/$DOCID" | python3 -c "import sys,json; print(json.load(sys.stdin)['document']['status'])")
expect "final status is completed" "completed" "$STATUS"

say "7. Archive and restore"
expect "clerk cannot archive"         403 "$(post clerk "/documents/$DOCID/archive" --data-urlencode "reason=nope")"
expect "admin archives"               302 "$(post admin "/documents/$DOCID/archive" --data-urlencode "reason=Filed after audit")"
ACTIVE=$(props admin "/documents" | python3 -c "
import sys,json
d=json.load(sys.stdin)['documents']['data']
print(sum(1 for r in d if r['id']==$DOCID))")
expect "gone from the active list"      0 "$ACTIVE"
ARCH=$(props admin "/archive" | python3 -c "
import sys,json
d=json.load(sys.stdin)['documents']['data']
print(sum(1 for r in d if r['id']==$DOCID))")
expect "present in the archive"         1 "$ARCH"
expect "admin restores (303 per Inertia)" 303 "$(curl -s -b "$(jar_for admin)" -o /dev/null -w '%{http_code}' -X DELETE "$B/documents/$DOCID/archive" -H "X-XSRF-TOKEN: $(php -r '$c=file_get_contents("/tmp/cicto-e2e-admin.jar"); if (preg_match("/XSRF-TOKEN\s+(\S+)/",$c,$m)) echo urldecode($m[1]);')" -H "X-Requested-With: XMLHttpRequest" -H "X-Inertia: true" -H "X-Inertia-Version: x")"
BACK=$(props admin "/documents" | python3 -c "
import sys,json
d=json.load(sys.stdin)['documents']['data']
print(sum(1 for r in d if r['id']==$DOCID))")
expect "back in the active list"        1 "$BACK"

say "8. Exports"
for f in csv xlsx pdf; do
  expect "export $f" 200 "$(curl -s -b "$(jar_for admin)" -o /tmp/cicto-export.$f -w '%{http_code}' "$B/reports/export?format=$f")"
done
expect "csv is not empty"  1 "$([ -s /tmp/cicto-export.csv ] && echo 1 || echo 0)"
expect "xlsx is a zip"     1 "$(head -c2 /tmp/cicto-export.xlsx | grep -q 'PK' && echo 1 || echo 0)"
expect "pdf is a pdf"      1 "$(head -c4 /tmp/cicto-export.pdf | grep -q '%PDF' && echo 1 || echo 0)"
expect "clerk cannot export" 403 "$(get clerk '/reports/export?format=csv')"

say "9. Cross-office isolation"
OTHER=$(php -r '
require "/Users/joshuagencianeo/Documents/GitHub/CICTO/vendor/autoload.php";
$app = require "/Users/joshuagencianeo/Documents/GitHub/CICTO/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$o = App\Models\Office::skip(1)->first();
$u = App\Models\User::factory()->create(["office_id"=>$o->id]);
$u->forceFill(["role"=>"admin","is_active"=>true,"email_verified_at"=>now(),"password"=>bcrypt("password")])->save();
$d = app(App\Actions\Documents\RegisterDocument::class)->handle(
  title: "Other office secret", documentTypeId: App\Models\DocumentType::first()->id,
  priority: App\Enums\DocumentPriority::Normal, originatingOffice: $o, creator: $u,
);
echo $u->email, " ", $d->id;
' 2>/dev/null)
OEMAIL=$(echo "$OTHER" | cut -d" " -f1); ODOC=$(echo "$OTHER" | cut -d" " -f2)
expect "other-office admin signs in"  302 "$(login other "$OEMAIL")"
expect "our admin cannot see theirs"  403 "$(get admin "/documents/$ODOC")"
expect "their admin can"              200 "$(get other "/documents/$ODOC")"
HIT=$(props admin "/documents?q=secret" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['documents']['data']))")
expect "search does not reveal it"      0 "$HIT"

printf '\n\033[1m%d passed, %d failed\033[0m\n\n' "$PASS" "$FAIL"
exit $([ "$FAIL" -eq 0 ] && echo 0 || echo 1)
