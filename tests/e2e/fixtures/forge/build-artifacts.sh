#!/usr/bin/env bash
# Builds minimal but valid Nextcloud app tarballs (+ .sha256 siblings) that the
# fixture forge serves, so an install can be driven end-to-end without a real
# forge. Each archive is `{appid}/appinfo/info.xml` — the single-root layout the
# external installer expects.
set -euo pipefail
OUT="${1:?usage: build-artifacts.sh <outdir>}"
mkdir -p "$OUT"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

infoxml() { # appid version
  cat <<XML
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
	<id>$1</id>
	<name>Forge Fixture App</name>
	<summary>Fixture app served by the e2e forge double</summary>
	<description>A minimal, valid Nextcloud app used only by the App Versions e2e suite to exercise forge installs.</description>
	<version>$2</version>
	<licence>EUPL-1.2</licence>
	<author>Conduction B.V.</author>
	<namespace>ForgeFixtureApp</namespace>
	<category>tools</category>
	<bugs>https://example.test/issues</bugs>
	<dependencies>
		<nextcloud min-version="31" max-version="34"/>
	</dependencies>
</info>
XML
}

make() { # filename appid infoversion [extrabyte]
  local file="$1" appid="$2" ver="$3" extra="${4:-}"
  rm -rf "$tmp/build"; mkdir -p "$tmp/build/$appid/appinfo"
  infoxml "$appid" "$ver" > "$tmp/build/$appid/appinfo/info.xml"
  # optional extra byte to make otherwise-identical archives differ (TOFU tamper)
  [ -n "$extra" ] && printf '%s' "$extra" > "$tmp/build/$appid/appinfo/.variant"
  tar -C "$tmp/build" -czf "$OUT/$file" "$appid"
  ( cd "$OUT" && sha256sum "$file" | awk '{print $1}' > "$file.sha256" )
}

# Well-formed releases for fixtureapp: below, at, and above the 1.0.0 baseline.
make fixtureapp-0.9.0.tar.gz fixtureapp 0.9.0
make fixtureapp-1.0.0.tar.gz fixtureapp 1.0.0
make fixtureapp-1.0.1.tar.gz fixtureapp 1.0.1
make fixtureapp-1.1.0.tar.gz fixtureapp 1.1.0
# TOFU tamper: same tag 1.0.1, different bytes (simulates a rewritten release).
make fixtureapp-1.0.1-tampered.tar.gz fixtureapp 1.0.1 tampered
# Integrity failures: appId mismatch, version mismatch (tag says 1.0.1, info says 9.9.9).
make fixtureapp-wrongid.tar.gz notfixtureapp 1.0.1
make fixtureapp-wrongversion.tar.gz fixtureapp 9.9.9

echo "built artifacts in $OUT:"; ls -1 "$OUT" | grep -v '.sha256$'
