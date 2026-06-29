<?php
/**
 * Output sanitization for AI-generated content.
 *
 * @package TejCart\AI_Content_Smartsuite\Content
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Content;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Formatter {
    public static function format_name( string $raw ): string {
        $clean = wp_strip_all_tags( $raw );
        $clean = trim( $clean );
        $clean = trim( $clean, "\"'`“”‘’" );
        $clean = (string) preg_replace( '/\s+/u', ' ', $clean );
        return trim( $clean );
    }

    public static function format_html_body( string $raw ): string {
        $raw = trim( $raw );
        if ( '' === $raw ) {
            return '';
        }
        $raw = self::markdown_to_html( $raw );
        return wp_kses_post( $raw );
    }

    private static function markdown_to_html( string $text ): string {
        if ( false === strpos( $text, '*' ) && false === strpos( $text, '#' ) && false === strpos( $text, '- ' ) && false === strpos( $text, '1.' ) ) {
            return $text;
        }

        $text = (string) preg_replace( "/\r\n?/", "\n", $text );

        // Headings: ### Title -> <h3>Title</h3> (h2-h4 only).
        $text = (string) preg_replace( '/^####\s+(.+)$/m', '<h4>$1</h4>', $text );
        $text = (string) preg_replace( '/^###\s+(.+)$/m', '<h3>$1</h3>', $text );
        $text = (string) preg_replace( '/^##\s+(.+)$/m', '<h3>$1</h3>', $text );

        // Bold: **text** or __text__ -> <strong>text</strong>.
        $text = (string) preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
        $text = (string) preg_replace( '/__(.+?)__/s', '<strong>$1</strong>', $text );

        // Italic: *text* or _text_ -> <em>text</em>.
        $text = (string) preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text );
        $text = (string) preg_replace( '/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<em>$1</em>', $text );

        // Unordered list blocks: consecutive lines starting with `- `.
        $text = (string) preg_replace_callback(
            '/(?:^[-*]\s+.+$\n?)+/m',
            static function ( array $m ): string {
                $items = preg_split( '/\n/', trim( $m[0] ) ) ?: array();
                $lis   = '';
                foreach ( $items as $line ) {
                    $content = (string) preg_replace( '/^[-*]\s+/', '', $line );
                    $content = trim( $content );
                    if ( '' !== $content ) {
                        $lis .= '<li>' . $content . '</li>';
                    }
                }
                return '<ul>' . $lis . '</ul>';
            },
            $text
        );

        // Ordered list blocks: consecutive lines starting with `1. `, `2. `, etc.
        $text = (string) preg_replace_callback(
            '/(?:^\d+[.\)]\s+.+$\n?)+/m',
            static function ( array $m ): string {
                $items = preg_split( '/\n/', trim( $m[0] ) ) ?: array();
                $lis   = '';
                foreach ( $items as $line ) {
                    $content = (string) preg_replace( '/^\d+[.\)]\s+/', '', $line );
                    $content = trim( $content );
                    if ( '' !== $content ) {
                        $lis .= '<li>' . $content . '</li>';
                    }
                }
                return '<ol>' . $lis . '</ol>';
            },
            $text
        );

        // Orphan bold/italic markers from unbalanced markdown.
        $text = (string) preg_replace( '/\*{2,}/', '', $text );
        $text = (string) preg_replace( '/_{2,}/', '', $text );

        return $text;
    }

    /**
     * @return array{list:array<int,string>, display:string}
     */
    public static function format_tags( string $raw ): array {
        $raw   = wp_strip_all_tags( $raw );
        $raw   = (string) preg_replace( '/[\r\n;|]+/', ',', $raw );
        $parts = array_map( 'trim', explode( ',', $raw ) );
        $clean = array();
        foreach ( $parts as $p ) {
            $p = trim( (string) preg_replace( '/^[\-\*\d\.\)\s]+/u', '', $p ) );
            $p = trim( $p, "\"'`“”‘’" );
            if ( '' === $p ) {
                continue;
            }
            $clean[] = $p;
        }
        $clean = array_values( array_unique( $clean ) );

        return array(
            'list'    => $clean,
            'display' => implode( ', ', $clean ),
        );
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    public static function format_faqs( string $raw ): array {
        $raw  = trim( $raw );
        if ( '' === $raw ) {
            return array();
        }
        $raw = (string) preg_replace( "/\r\n?/", "\n", $raw );

        $pairs = array();

        // HTML-structured FAQs. The OpenAI client's system prompt forces
        // HTML output (`<strong>`, `<p>`, `<ul>` …), so the live model
        // almost always returns questions as headings (`<h3>…</h3>`) or
        // whole-paragraph bold (`<p><strong>…?</strong></p>`) with the
        // answer in the following block(s). None of the plain-text /
        // markdown splitters below can see those boundaries, so run this
        // first whenever the payload carries tags.
        if ( false !== strpos( $raw, '<' ) ) {
            $pairs = self::split_by_html_blocks( $raw );
        }

        // Most specific: `**Q1:** ... **A:** ... **Q2:** ...` (and its
        // un-bolded / numbered cousins). Run first so the looser markers
        // don't claim the input.
        if ( empty( $pairs ) ) {
            $pairs = self::split_by_numbered_qa_pairs( $raw );
        }

        if ( empty( $pairs ) && preg_match( '/Q\s*[:\.\-]/i', $raw ) && preg_match( '/A\s*[:\.\-]/i', $raw ) ) {
            $pairs = self::split_by_qa_markers( $raw );
        }
        if ( empty( $pairs ) && false !== stripos( $raw, 'Question' ) && false !== stripos( $raw, 'Answer' ) ) {
            $pairs = self::split_by_question_answer( $raw );
        }
        if ( empty( $pairs ) ) {
            $pairs = self::split_by_numbered_questions( $raw );
        }
        if ( empty( $pairs ) ) {
            $pairs = self::split_by_question_marks( $raw );
        }

        $out = array();
        foreach ( $pairs as $pair ) {
            $q = self::sanitize_faq_field( $pair['question'] ?? '' );
            $a = self::sanitize_faq_field( $pair['answer']   ?? '' );
            if ( '' === $q || '' === $a ) {
                continue;
            }
            $out[] = array( 'question' => $q, 'answer' => $a );
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @return array<int, array{question:string, answer:string}>
     */
    public static function sanitize_faq_input( $value ): array {
        if ( ! is_array( $value ) ) {
            return array();
        }
        $out = array();
        foreach ( $value as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $raw_q = (string) ( $row['question'] ?? '' );
            $raw_a = (string) ( $row['answer']   ?? '' );

            // Self-heal: if the legacy stored row crams every Q/A pair
            // into one blob (`**Q1: ... **A: ... **Q2: ...`), re-parse it
            // so the admin Edit modal and the frontend tab both see real
            // separate pairs.
            $combined = trim( $raw_q . "\n" . $raw_a );
            if ( self::looks_like_multi_pair( $combined ) ) {
                $reparsed = self::format_faqs( $combined );
                if ( count( $reparsed ) > 1 ) {
                    foreach ( $reparsed as $pair ) {
                        $out[] = $pair;
                    }
                    continue;
                }
            }

            $q = self::sanitize_faq_field( $raw_q );
            $a = self::sanitize_faq_field( $raw_a );
            if ( '' === $q || '' === $a ) {
                continue;
            }
            $out[] = array( 'question' => $q, 'answer' => $a );
        }
        return $out;
    }

    /**
     * True when a string carries two-or-more embedded numbered question
     * markers (`Q1:`, `**Q2:**`, `Question 3:`). Used to detect malformed
     * stored rows that need to be re-split.
     */
    private static function looks_like_multi_pair( string $text ): bool {
        $stripped = (string) preg_replace( '/\*\*+/u', '', $text );
        if ( preg_match_all( '/Q\s*\d+\s*[:\.\)]/i', $stripped ) >= 2 ) {
            return true;
        }
        if ( preg_match_all( '/Question\s*\d*\s*[:\.]/i', $stripped ) >= 2 ) {
            return true;
        }
        // HTML-structured blob crammed into one stored row: two or more
        // heading questions, or two or more whole-paragraph bold runs.
        // Heals the legacy rows produced before the HTML splitter existed.
        if ( preg_match_all( '#<h[1-6]\b#i', $text ) >= 2 ) {
            return true;
        }
        if ( preg_match_all( '#<(?:strong|b)\b[^>]*>[^<]*\?#i', $text ) >= 2 ) {
            return true;
        }
        return false;
    }

    public static function encode_faqs( array $faqs ): string {
        $encoded = wp_json_encode( array_values( $faqs ) );
        return is_string( $encoded ) ? $encoded : '[]';
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    public static function decode_faqs( $value ): array {
        if ( is_array( $value ) ) {
            return self::sanitize_faq_input( $value );
        }
        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return array();
        }
        $decoded = json_decode( $value, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }
        return self::sanitize_faq_input( $decoded );
    }

    private static function sanitize_faq_field( string $value ): string {
        $value = self::strip_markdown_markers( $value );
        $value = wp_kses_post( $value );
        $value = trim( $value );
        return $value;
    }

    /**
     * Strip the markdown emphasis / heading markers that OpenAI sometimes
     * sprinkles into FAQ output. Handles BOTH the well-formed case
     * (`**bold**`) and the malformed case where the model opens a marker
     * but forgets to close it (`**Is this product...?` — observed in the
     * wild from gpt-3.5-turbo). HTML emphasis tags are preserved because
     * `wp_kses_post` allows them downstream.
     */
    private static function strip_markdown_markers( string $value ): string {
        // Balanced pairs first — preserves the inner text.
        $value = (string) preg_replace( '/\*\*+(.*?)\*\*+/u', '$1', $value );
        $value = (string) preg_replace( '/__+(.*?)__+/u', '$1', $value );
        $value = (string) preg_replace( '/(?<!\*)\*(?!\*)([^\*\n]+?)(?<!\*)\*(?!\*)/u', '$1', $value );
        $value = (string) preg_replace( '/(?<!_)_(?!_)([^_\n]+?)(?<!_)_(?!_)/u', '$1', $value );

        // Headings.
        $value = (string) preg_replace( '/^\s{0,3}#{1,6}\s+/m', '', $value );

        // Safety net: nuke any orphan bold/italic markers left behind by
        // unbalanced openers/closers. `**` and `__` are never valid in
        // plain FAQ copy, so this is safe.
        $value = (string) preg_replace( '/\*{2,}/u', '', $value );
        $value = (string) preg_replace( '/_{2,}/u', '', $value );

        // Strip orphan single `*` / `_` at line starts (markdown list bullets).
        $value = (string) preg_replace( '/^\s*[\*_]\s+/m', '', $value );

        // Collapse whitespace introduced by stripping.
        $value = (string) preg_replace( '/[ \t]{2,}/', ' ', $value );
        return trim( $value );
    }

    /**
     * Handle the `**Q1:** ... **A:** ... **Q2:** ... **A:** ...` shape
     * (and its un-bolded / `Q1.` / `Question 1:` cousins) that
     * gpt-3.5-turbo emits when the prompt asks for numbered FAQs. The
     * other splitters fail on this because everything is on one line.
     *
     * @return array<int, array{question:string, answer:string}>
     */
    private static function split_by_numbered_qa_pairs( string $raw ): array {
        // Strip ** bolding so the regex doesn't have to know about it,
        // and normalise `Question N` to `Q N`.
        $normalised = (string) preg_replace( '/\*\*+/u', '', $raw );
        $normalised = (string) preg_replace( '/Question\s*(\d+)/i', 'Q$1', $normalised );

        // Need at least two `Q\d` markers to qualify as numbered. We
        // intentionally don't require a word boundary before `Q` — legacy
        // stored blobs concatenate `Questions****Q1:` into `QuestionsQ1:`
        // once `**` is stripped, so demanding a boundary would miss the
        // first marker.
        if ( preg_match_all( '/Q\s*\d+\s*[:\.\)]/i', $normalised ) < 2 ) {
            return array();
        }

        // Split into per-question blocks. The lookahead keeps the marker
        // attached to the block that follows.
        $blocks = preg_split( '/(?=Q\s*\d+\s*[:\.\)])/u', $normalised ) ?: array();

        $pairs = array();
        foreach ( $blocks as $block ) {
            $block = ltrim( (string) $block );
            if ( '' === $block ) {
                continue;
            }
            if ( ! preg_match( '/^Q\s*\d+\s*[:\.\)]\s*(.+?)\s+A\s*\d*\s*[:\.\)]\s*(.+)$/isu', $block, $m ) ) {
                continue;
            }
            $pairs[] = array(
                'question' => trim( (string) preg_replace( '/\s+/u', ' ', $m[1] ) ),
                'answer'   => trim( (string) preg_replace( '/\s+/u', ' ', $m[2] ) ),
            );
        }
        return $pairs;
    }

    /**
     * Split HTML-structured FAQ output into Q&A pairs.
     *
     * The live OpenAI integration is instructed to return HTML, and the
     * model is wildly inconsistent about *which* tags it uses to mark a
     * question: headings (`<h3>…</h3>`), whole-paragraph bold
     * (`<p><strong>…?</strong></p>`), or a bold run buried inside a list
     * item (`<li><strong>…?</strong> answer text</li>`). Rather than
     * enumerate every wrapper shape, we treat the *question markers*
     * themselves — any heading, or any bold/strong run that reads as a
     * question (ends in "?") — as the only structure that matters. Each
     * marker is rewritten to a sentinel; everything between one sentinel
     * and the next becomes that question's answer once the surrounding
     * tags are flattened to plain text. A leading "Frequently Asked
     * Questions" style intro is skipped.
     *
     * @return array<int, array{question:string, answer:string}>
     */
    private static function split_by_html_blocks( string $raw ): array {
        $q_open  = "\x01Q\x01";
        $q_close = "\x01/Q\x01";

        // Headings are always treated as a question marker (an intro like
        // "Frequently Asked Questions" is filtered out further down).
        $marked = (string) preg_replace_callback(
            '#<(h[1-6])\b[^>]*>(.*?)</\1>#is',
            static function ( array $m ) use ( $q_open, $q_close ): string {
                $t = trim( wp_strip_all_tags( $m[2] ) );
                return '' === $t ? '' : $q_open . $t . $q_close;
            },
            $raw
        );

        // Bold/strong runs that read as a question — i.e. the run ends in
        // "?", or a "?" immediately follows the closing tag. Emphasis bold
        // inside an answer (which does not end in "?") is left untouched.
        $marked = (string) preg_replace_callback(
            '#<(strong|b)\b[^>]*>(.*?)</\1>(\s*\?)?#is',
            static function ( array $m ) use ( $q_open, $q_close ): string {
                $t        = trim( wp_strip_all_tags( $m[2] ) );
                $trailing = isset( $m[3] ) ? trim( $m[3] ) : '';
                if ( '' === $t ) {
                    return $m[0];
                }
                if ( '?' === substr( $t, -1 ) || '?' === $trailing ) {
                    if ( '?' !== substr( $t, -1 ) ) {
                        $t .= '?';
                    }
                    return $q_open . $t . $q_close;
                }
                return $m[0];
            },
            $marked
        );

        if ( false === strpos( $marked, $q_open ) ) {
            return array();
        }

        // Flatten what's left to plain text: turn block boundaries into
        // line breaks, then drop every remaining tag. The questions are
        // already safe inside the sentinels, so this only affects answers.
        $marked = (string) preg_replace( '#<(br)\b[^>]*/?>#i', "\n", $marked );
        $marked = (string) preg_replace( '#</(p|li|ul|ol|div|h[1-6]|blockquote|tr|td)>#i', "\n", $marked );
        $marked = wp_strip_all_tags( $marked );
        $marked = html_entity_decode( $marked, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Slice on the question sentinels: each match is one question plus
        // the answer text running up to the next question (or the end).
        if ( ! preg_match_all(
            '/' . preg_quote( $q_open, '/' ) . '(.*?)' . preg_quote( $q_close, '/' ) . '(.*?)(?=' . preg_quote( $q_open, '/' ) . '|$)/su',
            $marked,
            $matches,
            PREG_SET_ORDER
        ) ) {
            return array();
        }

        $pairs = array();
        foreach ( $matches as $m ) {
            $question = trim( (string) preg_replace( '/\s+/u', ' ', $m[1] ) );
            $answer   = isset( $m[2] ) ? (string) $m[2] : '';
            // Drop any stray sentinels and tidy whitespace in the answer.
            $answer = str_replace( array( $q_open, $q_close ), '', $answer );
            $answer = (string) preg_replace( '/[ \t]+/', ' ', $answer );
            $answer = (string) preg_replace( '/ *\n */', "\n", $answer );
            $answer = trim( (string) preg_replace( '/\n{3,}/', "\n\n", $answer ) );

            if ( '' === $question || self::is_faq_intro_heading( $question ) ) {
                continue;
            }
            if ( '' === $answer ) {
                continue;
            }
            $pairs[] = array( 'question' => $question, 'answer' => $answer );
        }

        return $pairs;
    }

    /**
     * True for a non-question intro heading the model prepends to a FAQ
     * block ("Frequently Asked Questions", "FAQs", "Common questions"),
     * which should not become a Q&A pair of its own.
     */
    private static function is_faq_intro_heading( string $text ): bool {
        $t = strtolower( trim( $text ) );
        $t = trim( $t, " \t\n\r\0\x0B—–-:•*#" );
        if ( '' === $t ) {
            return true;
        }
        if ( false !== strpos( $t, '?' ) ) {
            return false; // An actual question — keep it.
        }
        foreach ( array( 'frequently asked question', 'common question', 'faqs', 'faq' ) as $needle ) {
            if ( false !== strpos( $t, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    private static function split_by_qa_markers( string $raw ): array {
        $lines   = preg_split( "/\n+/", $raw ) ?: array();
        $pairs   = array();
        $current = array( 'question' => '', 'answer' => '' );
        $state   = null;

        $flush = function () use ( &$pairs, &$current ): void {
            if ( '' !== $current['question'] && '' !== $current['answer'] ) {
                $pairs[] = $current;
            }
            $current = array( 'question' => '', 'answer' => '' );
        };

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }
            if ( preg_match( '/^Q\s*[:\.\-]\s*(.+)$/i', $line, $m ) ) {
                if ( '' !== $current['answer'] ) {
                    $flush();
                }
                $current['question'] = trim( $m[1] );
                $state               = 'q';
                continue;
            }
            if ( preg_match( '/^A\s*[:\.\-]\s*(.+)$/i', $line, $m ) ) {
                $current['answer'] = trim( $m[1] );
                $state             = 'a';
                continue;
            }
            if ( 'q' === $state ) {
                $current['question'] = trim( $current['question'] . ' ' . $line );
            } elseif ( 'a' === $state ) {
                $current['answer'] = trim( $current['answer'] . ' ' . $line );
            }
        }
        $flush();
        return $pairs;
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    private static function split_by_question_answer( string $raw ): array {
        $stripped = (string) preg_replace( '/\*\*/', '', $raw );
        $stripped = (string) preg_replace( '/^#+\s*/m', '', $stripped );

        $blocks = preg_split( '/(?=Question\s*[:\.\-])/i', $stripped ) ?: array();
        $pairs  = array();
        foreach ( $blocks as $block ) {
            if ( '' === trim( $block ) ) {
                continue;
            }
            if ( ! preg_match( '/Question\s*[:\.\-]\s*(.*?)\s*Answer\s*[:\.\-]\s*(.+)$/is', $block, $m ) ) {
                continue;
            }
            $pairs[] = array(
                'question' => trim( (string) preg_replace( '/\s+/u', ' ', $m[1] ) ),
                'answer'   => trim( $m[2] ),
            );
        }
        return $pairs;
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    private static function split_by_numbered_questions( string $raw ): array {
        $blocks = preg_split( '/(?:^|\n)\s*\d+[\.\)]\s+/m', $raw ) ?: array();
        $pairs  = array();
        foreach ( $blocks as $block ) {
            $block = trim( $block );
            if ( '' === $block ) {
                continue;
            }
            if ( ! preg_match( '/^(.+?\?)(.*)$/su', $block, $m ) ) {
                continue;
            }
            $answer = trim( (string) preg_replace( '/\s+/u', ' ', $m[2] ) );
            $answer = ltrim( $answer, "-–—:\t " );
            if ( '' === $answer ) {
                continue;
            }
            $pairs[] = array(
                'question' => trim( $m[1] ),
                'answer'   => $answer,
            );
        }
        return $pairs;
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    private static function split_by_question_marks( string $raw ): array {
        $paragraphs = preg_split( "/\n\s*\n/", $raw ) ?: array();
        $pairs      = array();
        $pending_q  = '';
        foreach ( $paragraphs as $p ) {
            $p = trim( $p );
            if ( '' === $p ) {
                continue;
            }
            if ( '' === $pending_q && str_contains( $p, '?' ) ) {
                $pending_q = $p;
                continue;
            }
            if ( '' !== $pending_q ) {
                $pairs[]   = array( 'question' => $pending_q, 'answer' => $p );
                $pending_q = '';
            }
        }
        return $pairs;
    }
}
