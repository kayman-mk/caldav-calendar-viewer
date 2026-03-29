<?php
/**
 * Compiles a .po file into a binary .mo file (GNU MO format, little-endian).
 * Supports plural forms (msgid_plural / msgstr[n]).
 *
 * Usage: php compile-mo.php <input.po> <output.mo>
 */

if ( $argc !== 3 ) {
    fwrite( STDERR, "Usage: php compile-mo.php <input.po> <output.mo>\n" );
    exit( 1 );
}

$poFile = $argv[1];
$moFile = $argv[2];

if ( ! is_readable( $poFile ) ) {
    fwrite( STDERR, "Cannot read: $poFile\n" );
    exit( 1 );
}

// -----------------------------------------------------------------------
// Parse the .po file into entry objects.
// -----------------------------------------------------------------------
$unescape = fn( string $s ): string => stripcslashes( $s );

$extractQuoted = function ( string $line ) use ( $unescape ): string {
    if ( preg_match( '/^"(.*)"$/', trim( $line ), $m ) ) {
        return $unescape( $m[1] );
    }
    return '';
};

$entries = [];
$cur     = [ 'id' => null, 'id_plural' => null, 'str' => null, 'str_n' => [] ];
$inField = null;

$flush = function () use ( &$entries, &$cur, &$inField ) {
    if ( $cur['id'] !== null ) {
        $entries[] = $cur;
    }
    $cur     = [ 'id' => null, 'id_plural' => null, 'str' => null, 'str_n' => [] ];
    $inField = null;
};

foreach ( file( $poFile, FILE_IGNORE_NEW_LINES ) as $line ) {
    $line = rtrim( $line );

    if ( str_starts_with( $line, 'msgid_plural ' ) ) {
        $cur['id_plural'] = $extractQuoted( substr( $line, 13 ) );
        $inField          = 'id_plural';
        continue;
    }
    if ( str_starts_with( $line, 'msgid ' ) ) {
        $flush();
        $cur['id'] = $extractQuoted( substr( $line, 6 ) );
        $inField   = 'id';
        continue;
    }
    if ( preg_match( '/^msgstr\[(\d+)\]\s+(.*)/', $line, $m ) ) {
        $cur['str_n'][ (int) $m[1] ] = $extractQuoted( $m[2] );
        $inField                     = 'str_n_' . $m[1];
        continue;
    }
    if ( str_starts_with( $line, 'msgstr ' ) ) {
        $cur['str'] = $extractQuoted( substr( $line, 7 ) );
        $inField    = 'str';
        continue;
    }

    // Continuation lines.
    if ( str_starts_with( $line, '"' ) && $inField !== null ) {
        $chunk = $extractQuoted( $line );
        if ( $inField === 'id' ) {
            $cur['id'] .= $chunk;
        } elseif ( $inField === 'id_plural' ) {
            $cur['id_plural'] .= $chunk;
        } elseif ( $inField === 'str' ) {
            $cur['str'] .= $chunk;
        } elseif ( preg_match( '/^str_n_(\d+)$/', $inField, $m ) ) {
            $cur['str_n'][ (int) $m[1] ] .= $chunk;
        }
        continue;
    }

    // Any other non-blank, non-comment line ends continuation.
    if ( trim( $line ) !== '' && ! str_starts_with( $line, '#' ) ) {
        $inField = null;
    }
}
$flush();

// -----------------------------------------------------------------------
// Build pairs  [ original, translated ].
//
// Plural entry:
//   original   = singular NUL plural
//   translated = trans[0] NUL trans[1] NUL … trans[n-1]
// -----------------------------------------------------------------------
$pairs = [];

foreach ( $entries as $e ) {
    if ( $e['id'] === null ) {
        continue;
    }

    if ( $e['id_plural'] !== null ) {
        if ( empty( $e['str_n'] ) ) {
            continue;
        }
        ksort( $e['str_n'] );
        $pairs[] = [ $e['id'] . "\0" . $e['id_plural'], implode( "\0", $e['str_n'] ) ];
    } else {
        if ( $e['id'] === '' || $e['str'] === null || $e['str'] === '' ) {
            continue;
        }
        $pairs[] = [ $e['id'], $e['str'] ];
    }
}

// Sort originals lexicographically (required for MO binary search).
usort( $pairs, fn( $a, $b ) => strcmp( $a[0], $b[0] ) );

// -----------------------------------------------------------------------
// Write GNU MO binary (little-endian, revision 0).
//
// Header layout (7 × uint32):
//   0  magic            0x950412de
//   4  revision         0
//   8  N                number of strings
//  12  O                offset of original strings table
//  16  T                offset of translated strings table
//  20  S                hash table size  (0 = none)
//  24  H                hash table offset
// -----------------------------------------------------------------------
$n                     = count( $pairs );
$originalsTableOffset  = 28;
$translatedTableOffset = 28 + $n * 8;
$stringsOffset         = 28 + $n * 8 + $n * 8;

$origTable  = '';
$transTable = '';
$strings    = '';
$offset     = $stringsOffset;

foreach ( $pairs as [ $orig ] ) {
    $len        = strlen( $orig );
    $origTable .= pack( 'VV', $len, $offset );
    $strings   .= $orig . "\0";
    $offset    += $len + 1;
}

foreach ( $pairs as [ , $trans ] ) {
    $len         = strlen( $trans );
    $transTable .= pack( 'VV', $len, $offset );
    $strings    .= $trans . "\0";
    $offset     += $len + 1;
}

$header = pack( 'VVVVVVV', 0x950412de, 0, $n, $originalsTableOffset, $translatedTableOffset, 0, 28 + $n * 16 );

if ( file_put_contents( $moFile, $header . $origTable . $transTable . $strings ) === false ) {
    fwrite( STDERR, "Cannot write: $moFile\n" );
    exit( 1 );
}

echo "Written $moFile ($n strings)\n";
