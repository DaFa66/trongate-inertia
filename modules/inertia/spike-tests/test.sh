#!/usr/bin/env bash
# Inertia spike: exercise the whole protocol against t2 with curl.
set -u
cd "$(dirname "$0")"
JAR=cookies.txt
BASE=http://localhost/t2
INERTIA=(-H 'X-Inertia: true' -H 'X-Inertia-Version: 1')
rm -f "$JAR" shell.html page.json

echo "===== 1. plain browser hit -> HTML shell ====="
code=$(curl -s -o shell.html -w '%{http_code}' -c "$JAR" "$BASE/journal")
echo "HTTP $code | data-page present: $(grep -c 'data-page' shell.html)"
head -c 320 shell.html
echo

echo "===== 2. page object carried in the shell ====="
TOKEN=$(python extract_token.py shell.html)
echo "csrf_token length: ${#TOKEN}"

echo "===== 3. X-Inertia visit -> JSON page object ====="
curl -s -D headers_json.txt -b "$JAR" "${INERTIA[@]}" "$BASE/journal" -o page.json
grep -iE '^(HTTP/|content-type|x-inertia|vary)' headers_json.txt
python -m json.tool page.json

echo "===== 4. stale asset version -> 409 + X-Inertia-Location ====="
curl -s -o /dev/null -D - -b "$JAR" -H 'X-Inertia: true' -H 'X-Inertia-Version: 99' "$BASE/journal" | grep -iE '^(HTTP/|x-inertia-location)'

echo "===== 5. JSON POST with no token -> 403 ====="
curl -s -o no_token.txt -w 'HTTP %{http_code} -> ' -b "$JAR" "${INERTIA[@]}" \
  -H 'Content-Type: application/json' \
  -d '{"symbol":"acme","amount":"10"}' "$BASE/journal/store"
cat no_token.txt

echo "===== 6. JSON POST with token -> 303 + Location ====="
curl -s -o /dev/null -D - -b "$JAR" "${INERTIA[@]}" \
  -H 'Content-Type: application/json' \
  -d "{\"symbol\":\"acme\",\"amount\":\"10\",\"csrf_token\":\"$TOKEN\"}" \
  "$BASE/journal/store" | grep -iE '^(HTTP/|location|x-inertia)'

echo "===== 7. follow-up GET carries the saved row and the flash ====="
curl -s -b "$JAR" "${INERTIA[@]}" "$BASE/journal" -o page2.json
python -c "import json;p=json.load(open('page2.json'));print(json.dumps(p['props'],indent=2))"

echo "===== 8. failed validation -> errors in the next page's props ====="
curl -s -o /dev/null -b "$JAR" "${INERTIA[@]}" -H 'Content-Type: application/json' \
  -d "{\"symbol\":\"x\",\"amount\":\"abc\",\"csrf_token\":\"$TOKEN\"}" "$BASE/journal/store"
curl -s -b "$JAR" "${INERTIA[@]}" "$BASE/journal" -o page3.json
python -c "import json;p=json.load(open('page3.json'));print(json.dumps(p['props'].get('errors'),indent=2))"

echo "===== 9. external redirect -> 409 + X-Inertia-Location ====="
curl -s -o /dev/null -D - -b "$JAR" "${INERTIA[@]}" "$BASE/journal/external" | grep -iE '^(HTTP/|x-inertia-location)'

echo "===== 10. framework 404 on an Inertia visit ====="
curl -s -o 404.html -D - -b "$JAR" "${INERTIA[@]}" "$BASE/nosuchthing" | grep -iE '^(HTTP/|content-type)'
head -c 120 404.html
echo

echo "===== 11. app-level 404 -> Inertia page object with a 404 status ====="
curl -s -o page4.json -D - -b "$JAR" "${INERTIA[@]}" "$BASE/journal/missing_row" | grep -iE '^(HTTP/|content-type|x-inertia)'
python -m json.tool page4.json
