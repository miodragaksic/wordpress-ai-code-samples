<?php
/**
 * YardRedesign Point Edit - sanitized portfolio sample.
 *
 * Demonstrates the WordPress/backend architecture used for coordinate-based
 * local AI image edits. Production credentials, authentication, billing,
 * customer data and private storage logic are intentionally omitted.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_yard_portfolio_point_edit', 'yard_portfolio_point_edit_handler');

function yard_portfolio_point_edit_handler(): void
{
    // Production implementation should verify a WordPress nonce here.
    // Production authentication and usage/credit checks are intentionally omitted.

    $raw_points = isset($_POST['points'])
        ? wp_unslash((string) $_POST['points'])
        : '';

    $decoded = json_decode($raw_points, true);
    if (!is_array($decoded)) {
        wp_send_json_error(['message' => 'Invalid point payload.'], 400);
    }

    $points = yard_portfolio_normalize_points($decoded);
    if (!$points) {
        wp_send_json_error(['message' => 'Add at least one valid point instruction.'], 400);
    }

    // The production application obtains the active source image from protected
    // server-side state. No production URL, file path or customer data is exposed here.
    $source_image = apply_filters('yard_portfolio_source_image', '');

    if (!is_string($source_image) || $source_image === '') {
        wp_send_json_error([
            'message' => 'Portfolio adapter: provide a source image server-side.'
        ], 400);
    }

    $prompt = yard_portfolio_build_point_prompt($points);
    $result = yard_portfolio_ai_point_edit($source_image, $prompt);

    if (empty($result['ok'])) {
        wp_send_json_error([
            'message' => $result['error'] ?? 'Image edit failed.'
        ], 500);
    }

    wp_send_json_success([
        'image_url' => $result['image_url'] ?? '',
        'points_processed' => count($points),
    ]);
}

/**
 * Validate and normalize browser point data.
 * Coordinates are normalized to the 0..1 range so they remain independent
 * of the displayed image size.
 */
function yard_portfolio_normalize_points(array $input): array
{
    $points = [];

    foreach ($input as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = isset($item['id']) ? absint($item['id']) : 0;
        $x = isset($item['x']) ? (float) $item['x'] : -1;
        $y = isset($item['y']) ? (float) $item['y'] : -1;
        $text = isset($item['text'])
            ? sanitize_text_field((string) $item['text'])
            : '';

        if ($id < 1 || $text === '' || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
            continue;
        }

        $points[] = [
            'id' => $id,
            'x' => $x,
            'y' => $y,
            'text' => $text,
        ];
    }

    return array_slice($points, 0, 10);
}

/**
 * Build a strict prompt that keeps edits local to each selected coordinate.
 */
function yard_portfolio_build_point_prompt(array $points): string
{
    $prompt =
        "LOCAL POINT EDIT MODE.\n\n" .
        "Use the uploaded property image as a locked visual reference.\n" .
        "Edit only the local areas identified by the coordinates below.\n" .
        "Preserve unrelated areas, architecture, camera angle, perspective, framing and overall composition.\n" .
        "Do not add markers, coordinates, labels, numbers or watermarks to the final image.\n\n";

    foreach ($points as $point) {
        $px = (int) round($point['x'] * 100);
        $py = (int) round($point['y'] * 100);

        $prompt .= sprintf(
            "POINT %d:\n- Position: %d%% from left, %d%% from top\n- Instruction: %s\n- Apply this change only at or immediately around this point.\n- Do not apply the same change to similar objects elsewhere.\n\n",
            $point['id'],
            $px,
            $py,
            $point['text']
        );
    }

    $prompt .=
        "FINAL RULES:\n" .
        "- Return one realistic edited image.\n" .
        "- Every requested point edit should be visible.\n" .
        "- Coordinates have priority when locating the target.\n" .
        "- Do not redesign the whole property unless explicitly requested.\n";

    return $prompt;
}

/**
 * AI adapter boundary.
 *
 * The production implementation performs the server-side image-model request.
 * It is deliberately not included in this public sample because it contains
 * commercial integration details and protected configuration.
 *
 * Credentials must be loaded server-side from protected configuration or
 * environment variables. Never place API keys in JavaScript or commit them.
 */
function yard_portfolio_ai_point_edit(string $source_image, string $prompt): array
{
    // Example integration boundary only.
    // Replace this function inside a private/production implementation.

    return [
        'ok' => false,
        'error' => 'AI provider adapter intentionally omitted from public portfolio sample.',
    ];
}
