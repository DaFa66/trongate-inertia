import html
import json
import re
import sys

s = open(sys.argv[1], encoding="utf-8").read()
m = re.search(r"data-page='(.*?)'\s*>", s, re.S)
if not m:
    print("NO data-page FOUND", file=sys.stderr)
    sys.exit(1)
page = json.loads(html.unescape(m.group(1)))
print(page["props"]["csrf_token"])
