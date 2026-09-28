#!/usr/bin/env bash

set -euo pipefail

echo "========================================"
echo " Release chocoalano/panel"
echo "========================================"

# Pastikan tag terbaru dari remote tersedia
git fetch --tags origin

# Ambil versi terakhir berdasarkan semantic version
LAST_TAG=$(git tag --list 'v*' --sort=-v:refname | head -n 1)

# Jika belum pernah ada tag
if [ -z "$LAST_TAG" ]; then
    LAST_TAG="v0.0.0"
fi

echo "Versi terakhir: $LAST_TAG"

# Hilangkan prefix v
VERSION="${LAST_TAG#v}"

IFS='.' read -r MAJOR MINOR PATCH <<< "$VERSION"

# Naikkan PATCH
PATCH=$((PATCH + 1))

NEW_VERSION="v${MAJOR}.${MINOR}.${PATCH}"

echo "Versi baru: $NEW_VERSION"
echo

echo "1. Composer validate..."
composer validate --strict --no-check-publish

echo "2. Format check..."
composer run format-check

echo "3. Static analysis..."
composer run analyse

echo "4. Running tests..."
composer run test

echo "5. Git add..."
git add .

# Commit hanya jika ada perubahan
if git diff --cached --quiet; then
    echo "Tidak ada perubahan file untuk di-commit."
else
    echo "6. Commit..."
    git commit -m "Release ${NEW_VERSION}"
fi

echo "7. Push main..."
git push origin main

echo "8. Create tag ${NEW_VERSION}..."
git tag -a "$NEW_VERSION" -m "$NEW_VERSION"

echo "9. Push tag..."
git push origin "$NEW_VERSION"

echo
echo "========================================"
echo " Release berhasil: ${NEW_VERSION}"
echo "========================================"

echo
echo "Update package..."
composer update chocoalano/panel
