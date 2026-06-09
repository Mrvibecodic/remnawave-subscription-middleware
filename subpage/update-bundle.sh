#!/usr/bin/env bash
set -euo pipefail

REPO="${1:-remnawave/subscription-page}"
TAG="${2:-latest}"
OUT_DIR="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "[*] repo=$REPO tag=$TAG out=$OUT_DIR"

REG="https://registry-1.docker.io/v2/$REPO"
TOKEN="$(curl -fsS "https://auth.docker.io/token?service=registry.docker.io&scope=repository:$REPO:pull" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')"
[ -n "$TOKEN" ] || { echo "[!] no registry token"; exit 1; }

AHDR=(-H "Accept: application/vnd.oci.image.index.v1+json"
      -H "Accept: application/vnd.docker.distribution.manifest.list.v2+json"
      -H "Accept: application/vnd.oci.image.manifest.v1+json"
      -H "Accept: application/vnd.docker.distribution.manifest.v2+json")

curl -fsS -H "Authorization: Bearer $TOKEN" "${AHDR[@]}" "$REG/manifests/$TAG" -o "$WORK/top.json"

DIG="$(python3 -c 'import sys,json
d=json.load(open(sys.argv[1]));ms=d.get("manifests",[])
print(next((m["digest"] for m in ms if m.get("platform",{}).get("os")=="linux" and m.get("platform",{}).get("architecture")=="amd64"),""))' "$WORK/top.json")"
[ -n "$DIG" ] || DIG="$TAG"

curl -fsS -H "Authorization: Bearer $TOKEN" "${AHDR[@]}" "$REG/manifests/$DIG" -o "$WORK/amd.json"

mkdir -p "$WORK/root"
LAYERS="$(python3 -c 'import sys,json
[print(l["digest"]) for l in json.load(open(sys.argv[1]))["layers"]]' "$WORK/amd.json")"
echo "[*] layers: $(echo "$LAYERS" | wc -l)"
for d in $LAYERS; do
  curl -fsSL -H "Authorization: Bearer $TOKEN" "$REG/blobs/$d" -o "$WORK/l.tgz"
  tar -xzf "$WORK/l.tgz" -C "$WORK/root" 2>/dev/null || true
done

FE="$(find "$WORK/root" -type d -path '*opt/app/frontend' | head -n1)"
[ -n "$FE" ] && [ -f "$FE/index.html" ] || { echo "[!] frontend/index.html not found in image"; exit 1; }

PH_TITLE="$(grep -oE '<%[-=][^%]*metaTitle[^%]*%>' "$FE/index.html" | head -n1)"
PH_DESC="$(grep -oE '<%[-=][^%]*metaDescription[^%]*%>' "$FE/index.html" | head -n1)"
PH_DATA="$(grep -oE '<%[-=][^%]*panelData[^%]*%>' "$FE/index.html" | head -n1)"
if [ -z "$PH_TITLE" ] || [ -z "$PH_DESC" ] || [ -z "$PH_DATA" ]; then
  echo "[!] index.html placeholders changed - adapt PHP before updating. Aborting, current bundle kept."
  exit 2
fi

APP_ROUTE="$(grep -rhoE 'assets/\.app-config-v[0-9]+\.json' "$FE/assets" | sort -u | head -n1)"
[ -n "$APP_ROUTE" ] || { echo "[!] app-config route not found - adapt PHP. Aborting, current bundle kept."; exit 2; }
APP_ROUTE="/${APP_ROUTE#/}"

rm -rf "$OUT_DIR/assets" "$OUT_DIR/index.html"
cp -r "$FE/assets" "$OUT_DIR/assets"
cp "$FE/index.html" "$OUT_DIR/index.html"

python3 - "$OUT_DIR/manifest.json" "$REPO:$TAG" "$DIG" "$APP_ROUTE" "$PH_TITLE" "$PH_DESC" "$PH_DATA" <<'PY'
import sys, json, datetime
out, img, dig, route, t, d, p = sys.argv[1:8]
data = {
    "sourceImage": img,
    "imageDigest": dig,
    "appConfigRoute": route,
    "placeholders": {"metaTitle": t, "metaDescription": d, "panelData": p},
    "extractedAt": datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ"),
}
with open(out, "w", encoding="utf-8") as f:
    json.dump(data, f, ensure_ascii=False, indent=2)
    f.write("\n")
PY

echo "[OK] bundle updated:"
cat "$OUT_DIR/manifest.json"
