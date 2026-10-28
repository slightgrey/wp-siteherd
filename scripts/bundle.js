/**
 * Creates a distributable .zip of the plugin, excluding dev files.
 *
 * Output: dist/wp-siteherd-{version}.zip, with everything inside a
 * wp-siteherd/ folder (the plugin folder name WordPress installs it under).
 * Attach it to the GitHub release tagged v{version}; sites update from there.
 *
 * Usage:
 *   node scripts/bundle.js
 */

import archiver  from 'archiver';
import { createWriteStream, mkdirSync, readFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname( fileURLToPath( import.meta.url ) );
const root      = resolve( __dirname, '..' );

const pkg     = JSON.parse( readFileSync( resolve( root, 'package.json' ), 'utf8' ) );
const version = pkg.version;
const outDir  = resolve( root, 'dist' );
const outFile = resolve( outDir, `wp-siteherd-${ version }.zip` );

// The version in the plugin file must match, or sites would keep offering the update.
const php      = readFileSync( resolve( root, 'wp-siteherd.php' ), 'utf8' );
const header   = php.match( / \* Version:\s+([\d.]+)/ )?.[ 1 ];
const constant = php.match( /define\(\s*'SITEHERD_VERSION',\s*'([^']+)'/ )?.[ 1 ];
if ( header !== version || constant !== version ) {
  console.error( `Version mismatch: package.json ${ version }, plugin header ${ header }, SITEHERD_VERSION ${ constant }.` );
  console.error( 'Use npm run version:patch|minor|major to change versions.' );
  process.exit( 1 );
}

// Files and folders to exclude from the zip. composer.json must stay out:
// Siteherd_Self_Update treats a folder with composer.json as a dev copy and
// never updates it.
const EXCLUDE = [
  'node_modules/**',
  'dist/**',
  'scripts/**',
  'vendor/**',
  'tests/**',
  'docs/**',
  'package.json',
  'package-lock.json',
  'composer.json',
  'composer.lock',
  'phpunit.xml.dist',
  '.phpunit.cache/**',
  '.phpunit.result.cache',
  'README.md',
  '.gitignore',
  '.git/**',
  '**/*.map',
];

mkdirSync( outDir, { recursive: true } );

const output  = createWriteStream( outFile );
const archive = archiver( 'zip', { zlib: { level: 9 } } );

output.on( 'close', () => {
  const kb = ( archive.pointer() / 1024 ).toFixed( 1 );
  console.log( `\nBundle created: dist/wp-siteherd-${ version }.zip (${ kb } KB)` );
  console.log( `Attach it to the GitHub release tagged v${ version }.` );
} );

archive.on( 'warning', ( err ) => {
  if ( err.code === 'ENOENT' ) {
    console.warn( 'Warning:', err.message );
  } else {
    throw err;
  }
} );

archive.on( 'error', ( err ) => { throw err; } );

archive.pipe( output );

// Add everything under the root into a wp-siteherd/ folder inside the zip.
archive.glob( '**/*', {
  cwd: root,
  dot: false,
  ignore: EXCLUDE,
}, { prefix: 'wp-siteherd' } );

console.log( `Bundling wp-siteherd v${ version }...` );

archive.finalize();
