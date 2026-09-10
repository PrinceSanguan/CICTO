#!/bin/zsh
# usage: ./build.sh d1 "01-system-flowchart"
set -e
key=$1; name=$2
python3 ${key}_*.py
pdfs=()
for svg in build/${key}_p*.svg; do
  pdf=${svg%.svg}.pdf
  rsvg-convert -f pdf -o "$pdf" "$svg"
  pdfs+=("$pdf")
done
pdfunite "${pdfs[@]}" "build/cicto-${name}.pdf"
pdftoppm -r 60 -png "build/cicto-${name}.pdf" "preview/${key}"
pdfinfo "build/cicto-${name}.pdf" | grep -E "Pages|Page size"
ls preview | grep "^${key}"
