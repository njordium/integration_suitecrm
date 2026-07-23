#!/usr/bin/env node
/**
 * Verify package.json version matches appinfo/info.xml <version>.
 *
 * Runs as a `prebuild` and `predev` npm hook, so any `npm run build` or
 * `npm run dev` fails fast when the two version strings drift. This
 * closes the gap that let package.json sit at 1.2.0 all the way through
 * 2.0.x, 2.1.x, 2.2.x, and 2.3.x without anyone noticing, since nothing
 * in the app runtime actually reads package.json version.
 *
 * The check is deliberately dependency-free (no xml2js, no semver) so
 * it runs before the tree is built and cannot itself break the build
 * with an unresolved import.
 *
 * Exit code:
 *   0 on match
 *   1 on mismatch, with a diff-style message showing both values
 *
 * @author Kim Haverblad
 */

const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const packageJsonPath = path.join(root, 'package.json')
const infoXmlPath = path.join(root, 'appinfo', 'info.xml')

const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'))
const infoXml = fs.readFileSync(infoXmlPath, 'utf8')

// A single-line regex is enough since appinfo/info.xml keeps <version>
// on its own line in every historical revision; a stray multi-line
// value would be an authoring mistake regardless.
const match = infoXml.match(/<version>([^<]+)<\/version>/)
if (!match) {
	console.error('verify-version: <version> element not found in appinfo/info.xml')
	process.exit(1)
}

const packageVersion = packageJson.version
const infoVersion = match[1].trim()

if (packageVersion !== infoVersion) {
	console.error('verify-version: version mismatch')
	console.error('  package.json      : ' + packageVersion)
	console.error('  appinfo/info.xml  : ' + infoVersion)
	console.error('Bump both to the same value before building.')
	process.exit(1)
}

console.log('verify-version: OK (' + packageVersion + ')')
