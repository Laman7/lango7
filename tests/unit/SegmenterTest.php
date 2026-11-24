<?php
/**
 * Segmenting rules (spec 4.2).
 *
 * @package Lango7
 */

declare( strict_types=1 );

use Lango7\Translation\Placeholders;

// Inline markup becomes paired placeholders; attributes stay out of the text.
check(
	'inline link becomes a placeholder',
	array( 'Call <1>our team</1> today' ),
	segs( '<p>Call <a href="/contact/" class="x">our team</a> today</p>' )['texts']
);

check(
	'nested inline tags',
	array( 'Read <1><2>the <3>full</3> guide</2></1> now' ),
	segs( '<p>Read <a href="/g"><span>the <strong>full</strong> guide</span></a> now</p>' )['texts']
);

check(
	'nested inline tags without a wrapper span',
	array( 'Read <1>the <2>full</2> guide</1> now' ),
	segs( '<p>Read <em>the <strong>full</strong> guide</em> now</p>' )['texts']
);

// A lone wrapping inline element is unwrapped, so nav items share one string.
check(
	'standalone link is unwrapped',
	array( 'About us', 'Contact' ),
	segs( '<ul><li><a href="/about/">About us</a></li><li><a href="/contact/"><span>Contact</span></a></li></ul>' )['texts']
);

check(
	'implied </li> and </p>',
	array( 'One', 'Two', 'First', 'Second' ),
	segs( '<ul><li>One<li>Two</ul><div><p>First<p>Second</div>' )['texts']
);

check(
	'loose text next to blocks is its own segment',
	array( 'Intro text', 'Para', 'tail end' ),
	segs( '<div>Intro text <p>Para</p> tail end</div>' )['texts']
);

check(
	'link wrapping a card segments its blocks',
	array( 'Card title', 'Card body text' ),
	segs( '<a href="/x" class="brxe-block"><div><h3>Card title</h3><p>Card body text</p></div></a>' )['texts']
);

check(
	'icon svg inside a button link is a void placeholder',
	array( '<1/> Read more' ),
	segs( '<a class="brxe-button" href="/more"><svg viewBox="0 0 1 1"><title>arrow</title><path d=""/></svg> Read more</a>' )['texts']
);

check(
	'br and img become void placeholders, img alt is an attribute segment',
	array( 'Line one<1/>Line two <2/> here' ),
	segs( '<p>Line one<br>Line two <img src="a.png" alt="Small icon"> here</p>' )['texts']
);
check(
	'img alt inside a paragraph',
	array( 'alt=Small icon (attr)' ),
	segs( '<p>Line one<br>Line two <img src="a.png" alt="Small icon"> here</p>' )['attributes']
);

// Numbers.
$s = segs( '<p>Save 20% on 3 items until 31.12.2026</p>' );
check( 'numbers become placeholders', array( 'Save <n1/>% on <n2/> items until <n3/>' ), $s['texts'] );
check( 'number values kept per occurrence', array( 'n1' => '20', 'n2' => '3', 'n3' => '31.12.2026' ), $s['result']['texts'][0]->values );
check( 'numbers in words are left alone', array( 'Watch in 4K on iPhone <n1/>' ), segs( '<p>Watch in 4K on iPhone 15</p>' )['texts'] );

// Never translated.
check(
	'skipped elements',
	array( 'Visible' ),
	segs(
		'<div><script>var a = "<p>no</p>";</script><style>p{}</style><noscript>Enable JS</noscript>'
		. '<pre>code block</pre><p><code>inline code</code></p><textarea>typed</textarea>'
		. '<p translate="no">Brand</p><p class="x notranslate">Brand2</p><p data-lango7-skip>Skip</p>'
		. '<div id="wpadminbar"><a href="/wp-admin">Dashboard</a></div><p>Visible</p></div>'
	)['texts']
);
check(
	'user selectors',
	array( 'Kept' ),
	segs( '<div class="logo">Acme</div><p id="price">Price list</p><span data-sku>SKU text</span><p>Kept</p>', ".logo\n#price, [data-sku]" )['texts']
);
check(
	'notranslate span inside a sentence is opaque',
	array( 'Made by <1/> in Perth' ),
	segs( '<p>Made by <span class="notranslate">Acme Co</span> in Perth</p>' )['texts']
);

// Non-text segments are skipped.
check(
	'prices, dates, emails, URLs and punctuation are skipped',
	array(),
	segs( '<p>$1,299.00</p><td>12/03/2026</td><p>hello@example.com</p><p>https://example.com/x</p><span>—</span><p>  </p>' )['texts']
);

// Head and attributes.
$s = segs(
	'<!doctype html><html><head><title>Home &amp; Garden</title>'
	. '<meta name="description" content="Best plants in Perth">'
	. '<meta property="og:title" content="Home and Garden">'
	. '<meta name="twitter:description" content="Plants">'
	. '<meta name="viewport" content="width=device-width">'
	. '</head><body><input type="text" placeholder="Your name"><input type="submit" value="Send message">'
	. '<input type="hidden" value="secret"><button aria-label="Close menu">×</button><a title="Go home" href="/">Home</a></body></html>'
);
check( 'title segment', array( 'Home &amp; Garden', 'Home' ), $s['texts'] );
check( 'title kind', array( 'title', 'text' ), $s['kinds'] );
check(
	'head meta and body attributes',
	array(
		'content=Best plants in Perth (meta)',
		'content=Home and Garden (meta)',
		'content=Plants (meta)',
		'placeholder=Your name (attr)',
		'value=Send message (attr)',
		'aria-label=Close menu (attr)',
		'title=Go home (attr)',
	),
	$s['attributes']
);

// Hash stability: whitespace and entity spelling do not change the hash.
$a = segs( "<p>Fish  &amp;\n chips</p>" )['result']['texts'][0]->segment->hash;
$b = segs( '<p>Fish &#38; chips</p>' )['result']['texts'][0]->segment->hash;
check( 'hash ignores whitespace and entity spelling', $a, $b );
check( 'hash formula', Placeholders::hash( 'en', 'Fish &amp; chips' ), $a );
check( 'different numbers share a hash', segs( '<p>Save 20%</p>' )['result']['texts'][0]->segment->hash, segs( '<p>Save 35%</p>' )['result']['texts'][0]->segment->hash );
check( 'different hrefs share a hash', segs( '<p>See <a href="/a">this</a></p>' )['result']['texts'][0]->segment->hash, segs( '<p>See <a href="/b" class="y">this</a></p>' )['result']['texts'][0]->segment->hash );

// Raw text that looks like markup.
check(
	'script containing tags does not leak',
	array( 'After' ),
	segs( '<div><script>document.write("<div>Inside</div>")</script><p>After</p></div>' )['texts']
);
check(
	'comment inside sentence',
	array( 'Hello <1/> world' ),
	segs( '<p>Hello <!-- x --> world</p>' )['texts']
);
check(
	'select options are segments, not merged into the label',
	array( 'Sort by', 'Newest', 'Oldest' ),
	segs( '<label>Sort by <select><option>Newest<option>Oldest</select></label>' )['texts']
);
