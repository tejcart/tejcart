<?php
/**
 * Default prompt templates.
 *
 * @package TejCart\AI_Content_Smartsuite
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Default_Prompts {
    /**
     * @return array<string,string>
     */
    public static function all(): array {
        return array(
            'name_prompt'        => "Generate a product title for: {product_name}\nAttributes: {product_attributes}\n- Make the title clear, compelling, and optimized for search\n- Highlight important details like material, color, size, or benefit\n- Ensure it sounds appealing and relevant to shoppers\n- Limit the title to under 60 characters",
            'short_desc_prompt'  => "Generate a short product summary for: {product_name}\nAttributes: {product_attributes}\n- Focus on the product's materials, features, and key benefits\n- Use 5 to 7 bullet points to deliver value\n- Limit the entire output to under 50 words",
            'description_prompt' => "Create a detailed product description for: {product_name}\nAttributes: {product_attributes}\n- Focus on customer benefits, features, and real-world usage\n- Use a friendly and persuasive tone to improve conversions\n- Write short paragraphs or use bullet points for readability\n- Start each bullet point with a dash (\"-\") on a new line\n- Limit the total content to 100–150 words",
            'tags_prompt'        => "Generate 3 to 5 product tags for: {product_name}\nDescription: {product_description}\n- Create tags that reflect high-intent customer search behavior\n- Use 2 to 4 keywords or key phrases per tag\n- Emphasize product features, benefits, styles, and use cases\n- Ensure tags are short, relevant, and optimized for SEO",
            'faqs_prompt'        => "Write 3 to 5 frequently asked questions for: {product_name}\nDescription: {product_description}\n- Answer common customer concerns about features, usage, care, or delivery\n- Use a helpful and supportive tone to build trust\n- Ensure each question is relevant and clearly answered\n- Provide useful information that improves buying confidence\n- Limit each Q&A pair to 40–60 words",
        );
    }

    public static function get( string $key ): string {
        return self::all()[ $key ] ?? '';
    }

    /**
     * @return string[]
     */
    public static function keys(): array {
        return array_keys( self::all() );
    }
}
