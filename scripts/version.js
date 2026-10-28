/**
 * Bumps the plugin version across package.json, the main plugin PHP file
 * (header and SITEHERD_VERSION) and readme.txt (Stable tag), and adds an
 * empty changelog entry to readme.txt.
 *
 * Usage:
 *   node scripts/version.js patch   → 1.0.0 → 1.0.1
 *   node scripts/version.js minor   → 1.0.0 → 1.1.0
 *   node scripts/version.js major   → 1.0.0 → 2.0.0
 */

import { readFileSync, writeFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname( fileURLToPath( import.meta.url ) );
const root      = resolve( __dirname, '..' );

const type = process.argv[ 2 ];

if ( ! [ 'patch', 'minor', 'major' ].includes( type ) ) {
  console.error( 'Usage: node scripts/version.js <patch|minor|major>' );
  process.exit( 1 );
}

// ── Read current version from package.json ───────────────────────────────────

const pkgPath = resolve( root, 'package.json' );
const pkg     = JSON.parse( readFileSync( pkgPath, 'utf8' ) );
const [ major, minor, patch ] = pkg.version.split( '.' ).map( Number );

let newVersion;

if ( type === 'major' ) newVersion = `${ major + 1 }.0.0`;
if ( type === 'minor' ) newVersion = `${ major }.${ minor + 1 }.0`;
if ( type === 'patch' ) newVersion = `${ major }.${ minor }.${ patch + 1 }`;

console.log( `Bumping ${ type }: ${ pkg.version } → ${ newVersion }` );

// ── Update package.json ───────────────────────────────────────────────────────

pkg.version = newVersion;
writeFileSync( pkgPath, JSON.stringify( pkg, null, 2 ) + '\n', 'utf8' );
console.log( '  ✓ package.json' );

// ── Update main plugin PHP file ───────────────────────────────────────────────

const phpPath = resolve( root, 'wp-siteherd.php' );
let   phpContent = readFileSync( phpPath, 'utf8' );

// Plugin header: " * Version:           1.0.0"
phpContent = phpContent.replace(
  /( \* Version:\s+)[\d.]+/,
  `$1${ newVersion }`
);

// PHP constant: define( 'SITEHERD_VERSION', '1.0.0' )
phpContent = phpContent.replace(
  /(define\(\s*'SITEHERD_VERSION',\s*')[^']+(')/,
  `$1${ newVersion }$2`
);

writeFileSync( phpPath, phpContent, 'utf8' );
console.log( '  ✓ wp-siteherd.php' );

// ── Update readme.txt ─────────────────────────────────────────────────────────

const readmePath = resolve( root, 'readme.txt' );
let   readme     = readFileSync( readmePath, 'utf8' );

readme = readme.replace( /^(Stable tag:\s*).+$/m, `$1${ newVersion }` );

// New changelog entry at the top, to fill in before releasing.
if ( ! readme.includes( `= ${ newVersion } =` ) ) {
  readme = readme.replace( /(== Changelog ==\r?\n\r?\n)/, `$1= ${ newVersion } =\n* \n\n` );
}

writeFileSync( readmePath, readme, 'utf8' );
console.log( '  ✓ readme.txt (fill in the changelog entry)' );

console.log( `\nDone. Version is now ${ newVersion }` );
