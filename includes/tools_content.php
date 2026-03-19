<?php
/**
 * WPilot - AI Website Builder for WordPress
 * Copyright (c) 2026 Weblease. All rights reserved.
 *
 * This software is licensed, not sold. Unauthorized copying,
 * modification, or distribution is strictly prohibited.
 * License: https://weblease.se/terms
 *
 * Each copy is bound to a specific domain via license key.
 * Tampered or unlicensed copies will be disabled remotely.
 */
if (!defined('ABSPATH')) exit;

/**
 * WPilot Blog & Content Workflow Tools Module
 * Contains 10 tool cases for content operations.
 */
function wpilot_run_content_tools($tool, $params = []) {
    switch ($tool) {

        // ═════════════════════════════════════════════════════
        //  1. blog_publish_workflow — Complete post creation
        // ═════════════════════════════════════════════════════
        case 'blog_publish_workflow':
            $title       = sanitize_text_field($params['title'] ?? '');
            $content     = wp_kses_post($params['content'] ?? '');
            $category    = sanitize_text_field($params['category'] ?? '');
            $tags        = $params['tags'] ?? [];
            $image_url   = esc_url_raw($params['featured_image_url'] ?? $params['image_url'] ?? '');
            $schedule    = sanitize_text_field($params['schedule_date'] ?? '');
            $seo_title   = sanitize_text_field($params['seo_title'] ?? '');
            $meta_desc   = sanitize_text_field($params['meta_description'] ?? '');
            $author_id   = intval($params['author_id'] ?? get_current_user_id());
            $slug        = sanitize_title($params['slug'] ?? '');
            $excerpt     = sanitize_textarea_field($params['excerpt'] ?? '');
            $post_status = sanitize_key($params['status'] ?? 'draft');

            if (empty($title)) return wpilot_err('Post title is required.');
            if (empty($content)) return wpilot_err('Post content is required.');

            // Determine status
            $allowed_statuses = ['draft', 'publish', 'pending', 'private'];
            if ($schedule) {
                $post_status = 'future';
            } elseif (!in_array($post_status, $allowed_statuses)) {
                $post_status = 'draft';
            }

            // Resolve category
            $cat_ids = [];
            if ($category) {
                $cats = array_map('trim', explode(',', $category));
                foreach ($cats as $cat_name) {
                    $term = get_term_by('name', $cat_name, 'category');
                    if (!$term) {
                        $term = get_term_by('slug', sanitize_title($cat_name), 'category');
                    }
                    if (!$term) {
                        $inserted = wp_insert_term($cat_name, 'category');
                        if (!is_wp_error($inserted)) $cat_ids[] = $inserted['term_id'];
                    } else {
                        $cat_ids[] = $term->term_id;
                    }
                }
            }

            // Resolve tags
            if (is_string($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            }
            $tags = array_map('sanitize_text_field', (array) $tags);

            // Create post
            $post_data = [
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => $post_status,
                'post_author'  => $author_id,
                'post_type'    => 'post',
            ];
            if (!empty($cat_ids)) $post_data['post_category'] = $cat_ids;
            if (!empty($tags)) $post_data['tags_input'] = $tags;
            if ($slug) $post_data['post_name'] = $slug;
            if ($excerpt) $post_data['post_excerpt'] = $excerpt;
            if ($schedule && $post_status === 'future') {
                $post_data['post_date']     = date('Y-m-d H:i:s', strtotime($schedule));
                $post_data['post_date_gmt'] = get_gmt_from_date($post_data['post_date']);
            }

            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) return wpilot_err('Failed to create post: ' . $post_id->get_error_message());

            $extras = [];

            // Set featured image
            if ($image_url) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $img_id = media_sideload_image($image_url, $post_id, $title, 'id');
                if (!is_wp_error($img_id)) {
                    set_post_thumbnail($post_id, $img_id);
                    $extras[] = 'Featured image set';
                } else {
                    $extras[] = 'Featured image failed: ' . $img_id->get_error_message();
                }
            }

            // Set SEO meta (Yoast / Rank Math / AIOSEO)
            if ($seo_title) {
                update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
                update_post_meta($post_id, 'rank_math_title', $seo_title);
                update_post_meta($post_id, '_aioseo_title', $seo_title);
                $extras[] = 'SEO title set';
            }
            if ($meta_desc) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
                update_post_meta($post_id, 'rank_math_description', $meta_desc);
                update_post_meta($post_id, '_aioseo_description', $meta_desc);
                $extras[] = 'Meta description set';
            }

            $preview_url = get_preview_post_link($post_id);
            $edit_url    = admin_url('post.php?post=' . $post_id . '&action=edit');
            $permalink   = get_permalink($post_id);

            $status_label = $post_status === 'future' ? "scheduled for {$schedule}" : $post_status;

            return wpilot_ok("Post \"{$title}\" created as {$status_label} (ID: {$post_id})." . ($extras ? ' ' . implode('. ', $extras) . '.' : ''), [
                'post_id'    => $post_id,
                'status'     => $post_status,
                'preview'    => $preview_url,
                'edit_url'   => $edit_url,
                'permalink'  => $permalink,
                'schedule'   => $schedule ?: null,
            ]);

        // ═════════════════════════════════════════════════════
        //  2. content_calendar — Upcoming/recent posts
        // ═════════════════════════════════════════════════════
        case 'content_calendar':
            $limit = min(100, max(1, intval($params['limit'] ?? 30)));

            $scheduled = get_posts([
                'post_type'   => 'post',
                'post_status' => 'future',
                'numberposts' => $limit,
                'orderby'     => 'date',
                'order'       => 'ASC',
            ]);

            $drafts = get_posts([
                'post_type'   => 'post',
                'post_status' => 'draft',
                'numberposts' => $limit,
                'orderby'     => 'modified',
                'order'       => 'DESC',
            ]);

            $recent = get_posts([
                'post_type'   => 'post',
                'post_status' => 'publish',
                'numberposts' => min(20, $limit),
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);

            $format_post = function($p) {
                $cats = wp_get_post_categories($p->ID, ['fields' => 'names']);
                $author = get_the_author_meta('display_name', $p->post_author);
                return [
                    'id'       => $p->ID,
                    'title'    => $p->post_title,
                    'status'   => $p->post_status,
                    'date'     => $p->post_date,
                    'modified' => $p->post_modified,
                    'author'   => $author,
                    'categories' => $cats,
                    'edit_url' => admin_url('post.php?post=' . $p->ID . '&action=edit'),
                ];
            };
            return wpilot_ok(sprintf('Content calendar: %d scheduled, %d drafts, %d recent.', count($scheduled), count($drafts), count($recent)), [
                'scheduled' => array_map($format_post, $scheduled),
                'drafts'    => array_map($format_post, $drafts),
                'recent'    => array_map($format_post, $recent),
            ]);

        // ═════════════════════════════════════════════════════
        //  3. bulk_update_posts — Mass update
        // ═════════════════════════════════════════════════════
        case 'bulk_update_posts':
            $filter_cat    = sanitize_text_field($params['category'] ?? '');
            $filter_status = sanitize_key($params['status'] ?? '');
            $filter_author = intval($params['author'] ?? 0);
            $filter_from   = sanitize_text_field($params['date_from'] ?? '');
            $filter_to     = sanitize_text_field($params['date_to'] ?? '');
            $action        = sanitize_key($params['action'] ?? '');
            $action_value  = sanitize_text_field($params['value'] ?? '');
            $dry_run       = ($params['dry_run'] ?? 'yes') === 'yes';

            if (empty($action)) return wpilot_err('Action required. Options: set_status, set_category, add_tag, remove_tag, set_author, delete.');

            $args = [
                'post_type'   => 'post',
                'numberposts' => 500,
                'fields'      => 'ids',
            ];

            if ($filter_status) $args['post_status'] = $filter_status;
            else $args['post_status'] = 'any';

            if ($filter_author) $args['author'] = $filter_author;

            if ($filter_cat) {
                $term = get_term_by('name', $filter_cat, 'category') ?: get_term_by('slug', sanitize_title($filter_cat), 'category');
                if ($term) $args['category'] = $term->term_id;
                else return wpilot_err("Category \"{$filter_cat}\" not found.");
            }

            if ($filter_from || $filter_to) {
                $args['date_query'] = [];
                if ($filter_from) $args['date_query']['after'] = $filter_from;
                if ($filter_to)   $args['date_query']['before'] = $filter_to;
            }

            $post_ids = get_posts($args);
            if (empty($post_ids)) return wpilot_ok('No posts matched the filter criteria.', ['matched' => 0]);

            $count = count($post_ids);
            if ($dry_run) {
                return wpilot_ok("Dry run: {$count} posts would be affected by action \"{$action}\". Set dry_run: \"no\" to apply.", [
                    'matched' => $count,
                    'action'  => $action,
                    'value'   => $action_value,
                    'dry_run' => true,
                ]);
            }

            $updated = 0;
            foreach ($post_ids as $pid) {
                switch ($action) {
                    case 'set_status':
                        if (in_array($action_value, ['publish', 'draft', 'pending', 'private', 'trash'])) {
                            wp_update_post(['ID' => $pid, 'post_status' => $action_value]);
                            $updated++;
                        }
                        break;
                    case 'set_category':
                        $new_cat = get_term_by('name', $action_value, 'category') ?: get_term_by('slug', sanitize_title($action_value), 'category');
                        if (!$new_cat) {
                            $ins = wp_insert_term($action_value, 'category');
                            if (!is_wp_error($ins)) $new_cat = get_term($ins['term_id']);
                        }
                        if ($new_cat) {
                            wp_set_post_categories($pid, [$new_cat->term_id]);
                            $updated++;
                        }
                        break;
                    case 'add_tag':
                        wp_set_post_tags($pid, $action_value, true);
                        $updated++;
                        break;
                    case 'remove_tag':
                        $current = wp_get_post_tags($pid, ['fields' => 'names']);
                        $filtered = array_filter($current, function($t) use ($action_value) { return strtolower($t) !== strtolower($action_value); });
                        wp_set_post_tags($pid, $filtered);
                        $updated++;
                        break;
                    case 'set_author':
                        $new_author = intval($action_value);
                        if ($new_author && get_userdata($new_author)) {
                            wp_update_post(['ID' => $pid, 'post_author' => $new_author]);
                            $updated++;
                        }
                        break;
                    case 'delete':
                        wp_trash_post($pid);
                        $updated++;
                        break;
                }
            }

            return wpilot_ok("Bulk update complete: {$updated}/{$count} posts updated (action: {$action}).", [
                'matched' => $count,
                'updated' => $updated,
                'action'  => $action,
                'value'   => $action_value,
            ]);

        // ═════════════════════════════════════════════════════
        //  4. content_stats — Content analytics
        // ═════════════════════════════════════════════════════
        case 'content_stats':
            global $wpdb;
            $stats = [];

            // Posts by status
            $counts = wp_count_posts('post');
            $stats['posts'] = [
                'published' => (int) $counts->publish,
                'draft'     => (int) $counts->draft,
                'pending'   => (int) $counts->pending,
                'scheduled' => (int) $counts->future,
                'private'   => (int) $counts->private,
                'trash'     => (int) $counts->trash,
                'total'     => (int) $counts->publish + (int) $counts->draft + (int) $counts->pending + (int) $counts->future,
            ];

            // Pages by status
            $page_counts = wp_count_posts('page');
            $stats['pages'] = [
                'published' => (int) $page_counts->publish,
                'draft'     => (int) $page_counts->draft,
                'total'     => (int) $page_counts->publish + (int) $page_counts->draft,
            ];

            // Posts per category
            $categories = get_categories(['hide_empty' => false]);
            $by_cat = [];
            foreach ($categories as $cat) {
                $by_cat[$cat->name] = $cat->count;
            }
            arsort($by_cat);
            $stats['posts_per_category'] = $by_cat;

            // Word count average (sample last 50 published posts)
            $sample = get_posts([
                'post_type'   => 'post',
                'post_status' => 'publish',
                'numberposts' => 50,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);
            $word_counts = [];
            foreach ($sample as $p) {
                $word_counts[] = str_word_count(strip_tags($p->post_content));
            }
            $stats['word_count'] = [
                'average'  => count($word_counts) ? round(array_sum($word_counts) / count($word_counts)) : 0,
                'min'      => count($word_counts) ? min($word_counts) : 0,
                'max'      => count($word_counts) ? max($word_counts) : 0,
                'sampled'  => count($word_counts),
            ];

            // Publishing frequency (posts per month, last 6 months)
            $monthly = [];
            for ($i = 0; $i < 6; $i++) {
                $month_start = date('Y-m-01', strtotime("-{$i} months"));
                $month_end   = date('Y-m-t', strtotime("-{$i} months"));
                $month_label = date('Y-m', strtotime("-{$i} months"));
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date BETWEEN %s AND %s",
                    $month_start . ' 00:00:00',
                    $month_end . ' 23:59:59'
                ));
                $monthly[$month_label] = $count;
            }
            $stats['publishing_frequency'] = $monthly;

            // Top authors
            $authors = $wpdb->get_results(
                "SELECT post_author, COUNT(*) as post_count FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' GROUP BY post_author ORDER BY post_count DESC LIMIT 10"
            );
            $stats['top_authors'] = [];
            foreach ($authors as $a) {
                $user = get_userdata($a->post_author);
                $stats['top_authors'][] = [
                    'name'  => $user ? $user->display_name : 'Unknown',
                    'posts' => (int) $a->post_count,
                ];
            }

            // Most/least content categories
            if (!empty($by_cat)) {
                $stats['most_content_category']  = array_key_first($by_cat);
                $non_empty = array_filter($by_cat, function($c) { return $c > 0; });
                $stats['least_content_category'] = !empty($non_empty) ? array_key_last($non_empty) : null;
            }

            $total = $stats['posts']['total'];
// Built by Weblease
            return wpilot_ok("Content stats: {$total} posts total, {$stats['posts']['published']} published.", $stats);

        // ═════════════════════════════════════════════════════
        //  5. create_redirect — 301/302 redirect
        // ═════════════════════════════════════════════════════
        case 'create_redirect':
            $from = sanitize_text_field($params['from_url'] ?? $params['from'] ?? '');
            $to   = esc_url_raw($params['to_url'] ?? $params['to'] ?? '');
            $type = intval($params['type'] ?? 301);

            if (empty($from) || empty($to)) return wpilot_err('from_url and to_url are required.');
            if (!in_array($type, [301, 302])) $type = 301;

            // Normalize from path
            $from = '/' . ltrim(wp_parse_url($from, PHP_URL_PATH) ?: $from, '/');

            // Store redirect
            $redirects = get_option('wpilot_redirects', []);
            $redirects[$from] = [
                'to'         => $to,
                'type'       => $type,
                'created_at' => current_time('mysql'),
                'hits'       => 0,
            ];
            update_option('wpilot_redirects', $redirects, false);

            // Regenerate mu-plugin
            wpilot_regenerate_redirect_mu();

            return wpilot_ok("Redirect created: {$from} → {$to} ({$type}).", [
                'from' => $from,
                'to'   => $to,
                'type' => $type,
            ]);

        // ═════════════════════════════════════════════════════
        //  6. list_redirects — Show all active redirects
        // ═════════════════════════════════════════════════════
        case 'list_redirects':
            $redirects = get_option('wpilot_redirects', []);
            if (empty($redirects)) return wpilot_ok('No redirects configured.', ['redirects' => []]);

            $list = [];
            foreach ($redirects as $from => $r) {
                $list[] = [
                    'from'       => $from,
                    'to'         => $r['to'],
                    'type'       => $r['type'],
                    'created_at' => $r['created_at'] ?? '',
                    'hits'       => $r['hits'] ?? 0,
                ];
            }

            return wpilot_ok(count($list) . ' redirect(s) active.', ['redirects' => $list]);

        // ═════════════════════════════════════════════════════
        //  7. delete_redirect — Remove a redirect
        // ═════════════════════════════════════════════════════
        case 'delete_redirect':
            $from = sanitize_text_field($params['from_url'] ?? $params['from'] ?? '');
            if (empty($from)) return wpilot_err('from_url required.');

            $from = '/' . ltrim(wp_parse_url($from, PHP_URL_PATH) ?: $from, '/');
            $redirects = get_option('wpilot_redirects', []);

            if (!isset($redirects[$from])) return wpilot_err("No redirect found for \"{$from}\".");

            unset($redirects[$from]);
            update_option('wpilot_redirects', $redirects, false);

            wpilot_regenerate_redirect_mu();

            return wpilot_ok("Redirect removed: {$from}.", ['removed' => $from]);

        // ═════════════════════════════════════════════════════
        //  8. bulk_import_posts — Import from CSV
        // ═════════════════════════════════════════════════════
        case 'bulk_import_posts':
            $csv_url  = esc_url_raw($params['csv_url'] ?? '');
            $csv_data = $params['csv_data'] ?? '';
            $dry_run  = ($params['dry_run'] ?? 'yes') === 'yes';

            if (empty($csv_url) && empty($csv_data)) return wpilot_err('csv_url or csv_data required.');

            // Fetch CSV
            if ($csv_url) {
                $response = wp_remote_get($csv_url, ['timeout' => 30]);
                if (is_wp_error($response)) return wpilot_err('Failed to fetch CSV: ' . $response->get_error_message());
                $csv_data = wp_remote_retrieve_body($response);
            }

            // Parse CSV
            $lines = str_getcsv($csv_data, "\n");
            if (count($lines) < 2) return wpilot_err('CSV must have a header row and at least one data row.');

            $header = str_getcsv(array_shift($lines));
            $header = array_map(function($h) { return strtolower(trim($h)); }, $header);

            $required = ['title'];
            foreach ($required as $r) {
                if (!in_array($r, $header)) return wpilot_err("CSV missing required column: {$r}. Available: " . implode(', ', $header));
            }

            $imported = 0;
            $errors   = [];
            $results  = [];

            foreach ($lines as $i => $line) {
                $row = str_getcsv($line);
                if (count($row) !== count($header)) { $errors[] = "Row " . ($i + 2) . ": column count mismatch"; continue; }
                $data = array_combine($header, $row);

                $title   = sanitize_text_field($data['title'] ?? '');
                $content = wp_kses_post($data['content'] ?? '');
                if (empty($title)) { $errors[] = "Row " . ($i + 2) . ": empty title"; continue; }

                if ($dry_run) {
                    $results[] = ['row' => $i + 2, 'title' => $title, 'status' => 'would import'];
                    $imported++;
                    continue;
                }

                $post_data = [
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => sanitize_key($data['status'] ?? 'draft'),
                    'post_type'    => 'post',
                ];

                if (!empty($data['date'])) {
                    $post_data['post_date'] = date('Y-m-d H:i:s', strtotime($data['date']));
                }
                if (!empty($data['author'])) {
                    $author = get_user_by('login', $data['author']) ?: get_user_by('email', $data['author']);
                    if ($author) $post_data['post_author'] = $author->ID;
                }

                $pid = wp_insert_post($post_data, true);
                if (is_wp_error($pid)) { $errors[] = "Row " . ($i + 2) . ": " . $pid->get_error_message(); continue; }

                // Set category
                if (!empty($data['category'])) {
                    $cats = array_map('trim', explode('|', $data['category']));
                    $cat_ids = [];
                    foreach ($cats as $cn) {
                        $term = get_term_by('name', $cn, 'category') ?: get_term_by('slug', sanitize_title($cn), 'category');
                        if (!$term) { $ins = wp_insert_term($cn, 'category'); if (!is_wp_error($ins)) $cat_ids[] = $ins['term_id']; }
                        else $cat_ids[] = $term->term_id;
                    }
                    if ($cat_ids) wp_set_post_categories($pid, $cat_ids);
                }

                // Set tags
                if (!empty($data['tags'])) {
                    wp_set_post_tags($pid, array_map('trim', explode('|', $data['tags'])));
                }

                // Featured image
                if (!empty($data['featured_image'])) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    $img_id = media_sideload_image(esc_url_raw($data['featured_image']), $pid, $title, 'id');
                    if (!is_wp_error($img_id)) set_post_thumbnail($pid, $img_id);
                }

                $results[] = ['row' => $i + 2, 'title' => $title, 'post_id' => $pid, 'status' => 'imported'];
                $imported++;
            }

            $label = $dry_run ? 'Dry run' : 'Import';
            $err_count = count($errors);
            return wpilot_ok("{$label} complete: {$imported} posts processed" . ($err_count ? ", {$err_count} errors" : '') . '.', [
                'imported' => $imported,
                'errors'   => $errors,
                'results'  => $results,
                'dry_run'  => $dry_run,
            ]);

        // ═════════════════════════════════════════════════════
        //  9. stock_photo_search — Search Unsplash
        // ═════════════════════════════════════════════════════
        case 'stock_photo_search':
            $query = sanitize_text_field($params['query'] ?? '');
            $count = min(30, max(1, intval($params['count'] ?? 6)));

            if (empty($query)) return wpilot_err('Search query required.');

            $api_key = get_option('wpilot_unsplash_key', '');
            if (empty($api_key)) {
                return wpilot_err('Unsplash API key not configured. Get a free key at https://unsplash.com/developers — create an app, copy the Access Key, then save it: WPilot Settings or run wp option update wpilot_unsplash_key YOUR_KEY.', [
                    'setup_url' => 'https://unsplash.com/developers',
                    'save_with' => 'update_option("wpilot_unsplash_key", "YOUR_ACCESS_KEY")',
                ]);
            }

            $response = wp_remote_get('https://api.unsplash.com/search/photos?' . http_build_query([
                'query'    => $query,
                'per_page' => $count,
                'orientation' => sanitize_key($params['orientation'] ?? ''),
            ]), [
                'timeout' => 15,
                'headers' => ['Authorization' => 'Client-ID ' . $api_key],
            ]);

            if (is_wp_error($response)) return wpilot_err('Unsplash API error: ' . $response->get_error_message());

            $code = wp_remote_retrieve_response_code($response);
            if ($code === 401) return wpilot_err('Invalid Unsplash API key. Check wpilot_unsplash_key option.');
            if ($code === 403) return wpilot_err('Unsplash rate limit reached (50 requests/hour on free tier). Try again later.');
            if ($code !== 200) return wpilot_err("Unsplash API returned HTTP {$code}.");

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $photos = [];

            foreach ($body['results'] ?? [] as $p) {
                $photos[] = [
                    'id'           => $p['id'],
                    'description'  => $p['description'] ?? $p['alt_description'] ?? '',
                    'url_full'     => $p['urls']['full'] ?? '',
                    'url_regular'  => $p['urls']['regular'] ?? '',
                    'url_small'    => $p['urls']['small'] ?? '',
                    'download_url' => $p['links']['download'] ?? '',
                    'photographer' => $p['user']['name'] ?? '',
                    'profile'      => $p['user']['links']['html'] ?? '',
                    'attribution'  => 'Photo by ' . ($p['user']['name'] ?? 'Unknown') . ' on Unsplash',
                    'width'        => $p['width'] ?? 0,
                    'height'       => $p['height'] ?? 0,
                ];
            }

            $total = $body['total'] ?? 0;
            return wpilot_ok("Found {$total} photos for \"{$query}\" (showing " . count($photos) . ').',  [
                'query'  => $query,
                'total'  => $total,
                'photos' => $photos,
            ]);

        // ═════════════════════════════════════════════════════
        //  10. stock_photo_insert — Download & insert photo
        // ═════════════════════════════════════════════════════
        case 'stock_photo_insert':
            $url      = esc_url_raw($params['url'] ?? '');
            $alt_text = sanitize_text_field($params['alt_text'] ?? '');
            $post_id  = intval($params['post_id'] ?? 0);
            $credit   = sanitize_text_field($params['attribution'] ?? '');

            if (empty($url)) return wpilot_err('Photo URL required (use url from stock_photo_search results).');

            // Trigger download event for Unsplash API guidelines
            $api_key = get_option('wpilot_unsplash_key', '');
            if ($api_key && strpos($url, 'unsplash.com') !== false) {
                // Extract photo ID for download tracking
                if (preg_match('/photo-([a-zA-Z0-9_-]+)/', $url, $m)) {
                    wp_remote_get("https://api.unsplash.com/photos/{$m[1]}/download", [
                        'timeout' => 5,
                        'blocking' => false,
                        'headers' => ['Authorization' => 'Client-ID ' . $api_key],
                    ]);
                }
            }

            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $img_id = media_sideload_image($url, $post_id ?: 0, $alt_text ?: '', 'id');
            if (is_wp_error($img_id)) return wpilot_err('Failed to download image: ' . $img_id->get_error_message());

            // Set alt text with attribution
            $full_alt = $alt_text;
            if ($credit) {
                $full_alt = $alt_text ? $alt_text . ' — ' . $credit : $credit;
            }
            if ($full_alt) {
                update_post_meta($img_id, '_wp_attachment_image_alt', $full_alt);
            }

            // Store attribution as caption
            if ($credit) {
                wp_update_post(['ID' => $img_id, 'post_excerpt' => $credit]);
            }

            // Set as featured image if post_id given
            if ($post_id) {
                set_post_thumbnail($post_id, $img_id);
            }

            $img_url = wp_get_attachment_url($img_id);

            return wpilot_ok("Stock photo added to media library (ID: {$img_id})." . ($post_id ? " Set as featured image for post #{$post_id}." : ''), [
                'image_id'    => $img_id,
                'image_url'   => $img_url,
                'alt_text'    => $full_alt,
                'post_id'     => $post_id ?: null,
                'attribution' => $credit,
            ]);

        default:
            return null;
    }
}

// ═════════════════════════════════════════════════════════════
//  Redirect MU-Plugin Generator
//  Creates a mu-plugin that handles redirects BEFORE WordPress
//  loads themes — fast, early execution.
// ═════════════════════════════════════════════════════════════
function wpilot_regenerate_redirect_mu() {
    $redirects = get_option('wpilot_redirects', []);

    if (empty($redirects)) {
        // Remove mu-module if no redirects
        if (function_exists('wpilot_mu_remove')) {
            wpilot_mu_remove('redirects');
        }
        return;
    }

    // Build redirect map as PHP array
    $map_entries = [];
    foreach ($redirects as $from => $r) {
        $escaped_from = addslashes($from);
        $escaped_to   = addslashes($r['to']);
        $type         = intval($r['type']);
        $map_entries[] = "    '{$escaped_from}' => ['{$escaped_to}', {$type}]";
    }
    $map_php = implode(",\n", $map_entries);

    $mu_code = <<<MUPHP
// WPilot Redirects — runs before theme loads
add_action('template_redirect', function() {
    \$map = [
{$map_php}
    ];
    \$path = '/' . ltrim(wp_parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '', '/');
    \$path = rtrim(\$path, '/');
    // Try exact match first
    if (isset(\$map[\$path])) {
        // Track hit count
        \$redirects = get_option('wpilot_redirects', []);
        if (isset(\$redirects[\$path])) {
            \$redirects[\$path]['hits'] = (\$redirects[\$path]['hits'] ?? 0) + 1;
            update_option('wpilot_redirects', \$redirects, false);
        }
        wp_redirect(\$map[\$path][0], \$map[\$path][1]);
        exit;
    }
    // Try with trailing slash
    \$with_slash = \$path . '/';
    if (isset(\$map[\$with_slash])) {
        \$redirects = get_option('wpilot_redirects', []);
        if (isset(\$redirects[\$with_slash])) {
            \$redirects[\$with_slash]['hits'] = (\$redirects[\$with_slash]['hits'] ?? 0) + 1;
            update_option('wpilot_redirects', \$redirects, false);
        }
        wp_redirect(\$map[\$with_slash][0], \$map[\$with_slash][1]);
        exit;
    }
}, 1);
MUPHP;

    if (function_exists('wpilot_mu_register')) {
        wpilot_mu_register('redirects', $mu_code);
    }
}
