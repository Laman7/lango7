<?php
/**
 * Writing translations back and rewriting links.
 *
 * @package Lango7
 */

declare( strict_types=1 );

use Lango7\Html\Applier;
use Lango7\Html\LinkRewriter;
use Lango7\Html\Node;
use Lango7\Html\Parser;
use Lango7\Translation\Placeholders;

/**
 * Translate a page with a fixed dictionary, the way the pipeline does.
 *
 * @param array<string, string> $dict source text => translated text.
 */
function apply_dict( string $html, array $dict, ?LinkRewriter $links = null ): string {
	$s       = segs( $html );
	$applier = new Applier( $html );
	foreach ( $s['result']['attributes'] as $o ) {
		if ( isset( $dict[ $o->segment->text ] ) ) {
			$applier->setAttribute( $o->node, $o->attr, Placeholders::toPlain( $dict[ $o->segment->text ], $o->values ) );
		}
	}
	if ( $links ) {
		$walk = static function ( Node $n ) use ( &$walk, $applier, $links ): void {
			foreach ( $n->children as $c ) {
				if ( Node::ELEMENT === $c->type ) {
					if ( 'a' === $c->name && null !== $c->attr( 'href' ) ) {
						$new = $links->rewrite( (string) $c->attr( 'href' ), 'ms' );
						if ( null !== $new ) {
							$applier->setAttribute( $c, 'href', $new );
						}
					}
					$walk( $c );
				}
			}
		};
		$walk( $s['root'] );
	}
	foreach ( $s['result']['texts'] as $o ) {
		if ( isset( $dict[ $o->segment->text ] ) ) {
			$applier->replace( $o, $dict[ $o->segment->text ] );
		}
	}
	return $applier->output();
}

$links = new LinkRewriter( 'example.com', '', array( 'ms' ), array( '/my-account/*' ) );

check( 'empty saved text hides the segment', '<p></p>', apply_dict( '<p>Hello</p>', array( 'Hello' => '' ) ) );
check( 'empty saved attribute stays empty', '<input type=submit value="">', apply_dict( '<input type=submit value="Send">', array( 'Send' => '' ) ) );

check(
	'untouched page is byte-identical',
	"<!DOCTYPE html>\n<html><body class=x><p data-a='1'>Hello</p><img src=a.png alt=\"\"></body></html>",
	apply_dict( "<!DOCTYPE html>\n<html><body class=x><p data-a='1'>Hello</p><img src=a.png alt=\"\"></body></html>", array() )
);

check(
	'segment with a link keeps the link and rewrites it',
	'<p class="lead">  Hubungi <a href="/ms/contact/" class="btn">pasukan kami</a> hari ini </p>',
	apply_dict(
		'<p class="lead">  Call <a href="/contact/" class="btn">our team</a> today </p>',
		array( 'Call <1>our team</1> today' => 'Hubungi <1>pasukan kami</1> hari ini' ),
		$links
	)
);

check(
	'reordered placeholders render the right tags',
	'<p><em>Kedua</em> lalu <strong>pertama</strong></p>',
	apply_dict(
		'<p><strong>First</strong> then <em>second</em></p>',
		array( '<1>First</1> then <2>second</2>' => '<2>Kedua</2> lalu <1>pertama</1>' )
	)
);

check(
	'numbers come from this page',
	'<p>Jimat 35% hari ini</p>',
	apply_dict( '<p>Save 35% today</p>', array( 'Save <n1/>% today' => 'Jimat <n1/>% hari ini' ) )
);

check(
	'void placeholder keeps svg verbatim, attribute inside a segment translates',
	'<a href="/ms/more"><svg viewBox="0 0 1 1"><path d="M0"/></svg> Baca lagi <img src="x.png" alt="ikon"></a>',
	apply_dict(
		'<a href="/more"><svg viewBox="0 0 1 1"><path d="M0"/></svg> Read more <img src="x.png" alt="icon"></a>',
		array(
			'<1/> Read more <2/>' => '<1/> Baca lagi <2/>',
			'icon'                => 'ikon',
		),
		$links
	)
);

check(
	'unwrapped nav link: href rewritten outside the segment',
	'<li><a href="https://example.com/ms/about/?x=1&amp;y=2#team">Tentang kami</a></li>',
	apply_dict(
		'<li><a href="https://example.com/about/?x=1&amp;y=2#team">About us</a></li>',
		array( 'About us' => 'Tentang kami' ),
		$links
	)
);

check(
	'attribute escaping and unquoted attributes',
	'<input type=submit value="Hantar &quot;sekarang&quot;"><img alt="Logo &amp; nama" src=x>',
	apply_dict(
		'<input type=submit value=Send><img alt=Logo src=x>',
		array(
			'Send' => 'Hantar "sekarang"',
			'Logo' => 'Logo &amp; nama',
		)
	)
);

check(
	'title and meta',
	'<html><head><title>Rumah</title><meta name="description" content="Tumbuhan terbaik"></head><body></body></html>',
	apply_dict(
		'<html><head><title>Home</title><meta name="description" content="Best plants"></head><body></body></html>',
		array(
			'Home'        => 'Rumah',
			'Best plants' => 'Tumbuhan terbaik',
		)
	)
);

$html    = '<html class="no-js"><body><div lang>x</div></body></html>';
$root    = Parser::parse( $html );
$applier = new Applier( $html );
$applier->setAttribute( $root->children[0], 'lang', 'ms' );
$applier->setAttribute( $root->children[0], 'dir', 'ltr' );
$applier->setAttribute( $root->children[0]->children[0]->children[0], 'lang', 'ms' );
check( 'add and set attributes', '<html class="no-js" lang="ms" dir="ltr"><body><div lang="ms">x</div></body></html>', $applier->output() );

// Link rules.
$cases = array(
	'/about/'                              => '/ms/about/',
	'/'                                    => '/ms/',
	'https://example.com'                  => 'https://example.com/ms/',
	'https://EXAMPLE.com/a?b=1'            => 'https://EXAMPLE.com/ms/a?b=1',
	'//example.com/a/'                     => '//example.com/ms/a/',
	'/index.php?p=4'                       => '/ms/index.php?p=4',
	'/page.html'                           => '/ms/page.html',
	'https://other.com/about/'             => null,
	'/ms/about/'                           => null,
	'/ms'                                  => null,
	'/wp-admin/'                           => null,
	'/wp-login.php'                        => null,
	'/wp-content/uploads/2026/01/file.pdf' => null,
	'/wp-json/wp/v2/posts'                 => null,
	'/brochure.pdf'                        => null,
	'/feed.xml'                            => null,
	'/?rest_route=/x'                      => null,
	'/my-account/orders/'                  => null,
	'/my-account'                          => null,
	'#top'                                 => null,
	'mailto:a@b.c'                         => null,
	'tel:+60123'                           => null,
	'javascript:void(0)'                   => null,
	'relative/path'                        => null,
	'?page=2'                              => null,
);
foreach ( $cases as $in => $out ) {
	check( "link {$in}", $out, $links->rewrite( (string) $in, 'ms' ) );
}

$sub = new LinkRewriter( 'example.com:8080', '/blog', array( 'ms', 'cn' ) );
check( 'subdirectory install', 'http://example.com:8080/blog/cn/hello/', $sub->rewrite( 'http://example.com:8080/blog/hello/', 'cn' ) );
check( 'outside subdirectory', null, $sub->rewrite( '/shop/', 'cn' ) );
check( 'wrong port', null, $sub->rewrite( 'http://example.com/blog/hello/', 'cn' ) );
