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
 * WPilot Comment Moderation Tools Module
 * Contains 10 tool cases for comment operations.
 */
function wpilot_run_comment_tools($tool, $params = []) {
    switch ($tool) {

        // ═════════════════════════════════════════════════════
        //  1. list_comments
        // ═════════════════════════════════════════════════════
        case 'list_comments':
            $status_map = [
                'all'      => '',
                'approved' => 'approve',
                'pending'  => 'hold',
                'spam'     => 'spam',
                'trash'    => 'trash',
            ];
            $status   = sanitize_text_field($params['status'] ?? 'all');
            $wp_status = $status_map[$status] ?? '';
            $per_page = min(100, max(1, intval($params['per_page'] ?? 20)));
            $page     = max(1, intval($params['page'] ?? 1));

            $args = [
                'number' => $per_page,
                'offset' => ($page - 1) * $per_page,
                'orderby' => 'comment_date',
                'order'   => 'DESC',
            ];
            if ($wp_status) $args['status'] = $wp_status;

            $comments = get_comments($args);
            $count_args = $wp_status ? ['status' => $wp_status] : [];
            $total = (int) get_comments(array_merge($count_args, ['count' => true]));

            $list = [];
            foreach ($comments as $c) {
                $list[] = [
                    'id'       => (int) $c->comment_ID,
                    'author'   => sanitize_text_field($c->comment_author),
                    'email'    => sanitize_email($c->comment_author_email),
                    'content'  => wp_trim_words(wp_strip_all_tags($c->comment_content), 30),
                    'post_id'  => (int) $c->comment_post_ID,
                    'post'     => get_the_title($c->comment_post_ID),
                    'status'   => wp_get_comment_status($c),
                    'date'     => $c->comment_date,
                    'ip'       => $c->comment_author_IP,
                ];
            }

            return wpilot_ok("{$total} comment(s) found.", [
                'comments' => $list,
                'total'    => $total,
                'page'     => $page,
                'pages'    => ceil($total / $per_page),
            ]);

        // ═════════════════════════════════════════════════════
        //  2. approve_comment
        // ═════════════════════════════════════════════════════
        case 'approve_comment':
            $id = intval($params['id'] ?? $params['comment_id'] ?? 0);
            if (!$id) return wpilot_err('Comment ID required.');
            $comment = get_comment($id);
            if (!$comment) return wpilot_err("Comment #{$id} not found.");
            // Built by Weblease
            wp_set_comment_status($id, 'approve');
            return wpilot_ok("Comment #{$id} approved.");

        // ═════════════════════════════════════════════════════
        //  3. delete_comment
        // ═════════════════════════════════════════════════════
        case 'delete_comment':
            $id    = intval($params['id'] ?? $params['comment_id'] ?? 0);
            $force = !empty($params['force']);
            if (!$id) return wpilot_err('Comment ID required.');
            $comment = get_comment($id);
            if (!$comment) return wpilot_err("Comment #{$id} not found.");
            wp_delete_comment($id, $force);
            $action = $force ? 'permanently deleted' : 'trashed';
            return wpilot_ok("Comment #{$id} {$action}.");

        // ═════════════════════════════════════════════════════
        //  4. spam_comment
        // ═════════════════════════════════════════════════════
        case 'spam_comment':
            $id = intval($params['id'] ?? $params['comment_id'] ?? 0);
            if (!$id) return wpilot_err('Comment ID required.');
            $comment = get_comment($id);
            if (!$comment) return wpilot_err("Comment #{$id} not found.");
            wp_spam_comment($id);
            return wpilot_ok("Comment #{$id} marked as spam.");

        // ═════════════════════════════════════════════════════
        //  5. bulk_approve_comments
        // ═════════════════════════════════════════════════════
        case 'bulk_approve_comments':
            $pending = get_comments(['status' => 'hold', 'number' => 0]);
            $count   = 0;
            foreach ($pending as $c) {
                wp_set_comment_status($c->comment_ID, 'approve');
                $count++;
            }
            return wpilot_ok("{$count} pending comment(s) approved.");

        // ═════════════════════════════════════════════════════
        //  6. bulk_delete_spam
        // ═════════════════════════════════════════════════════
        case 'bulk_delete_spam':
            $spam  = get_comments(['status' => 'spam', 'number' => 0]);
            $count = 0;
            foreach ($spam as $c) {
                wp_delete_comment($c->comment_ID, true);
                $count++;
            }
            return wpilot_ok("{$count} spam comment(s) permanently deleted.");

        // ═════════════════════════════════════════════════════
        //  7. reply_to_comment
        // ═════════════════════════════════════════════════════
        case 'reply_to_comment':
            $parent_id = intval($params['id'] ?? $params['comment_id'] ?? $params['parent_id'] ?? 0);
            $content   = wp_kses_post($params['content'] ?? $params['reply'] ?? $params['message'] ?? '');
            if (!$parent_id) return wpilot_err('Parent comment ID required.');
            if (empty($content)) return wpilot_err('Reply content required.');

            $parent = get_comment($parent_id);
            if (!$parent) return wpilot_err("Comment #{$parent_id} not found.");

            $user = wp_get_current_user();
            $reply_id = wp_insert_comment([
                'comment_post_ID'  => $parent->comment_post_ID,
                'comment_parent'   => $parent_id,
                'comment_content'  => $content,
                'comment_author'   => $user->display_name ?: 'Admin',
                'comment_author_email' => $user->user_email ?: get_option('admin_email'),
                'comment_approved' => 1,
                'user_id'          => $user->ID,
                'comment_type'     => 'comment',
            ]);

            if (!$reply_id) return wpilot_err('Failed to post reply.');
            return wpilot_ok("Reply posted (ID: {$reply_id}) to comment #{$parent_id}.", ['reply_id' => $reply_id]);

        // ═════════════════════════════════════════════════════
        //  8. comment_stats
        // ═════════════════════════════════════════════════════
        case 'comment_stats':
            $counts = wp_count_comments();
            return wpilot_ok('Comment statistics.', [
                'stats' => [
                    'total'           => (int) $counts->total_comments,
                    'approved'        => (int) $counts->approved,
                    'pending'         => (int) $counts->moderated,
                    'spam'            => (int) $counts->spam,
                    'trash'           => (int) $counts->trash,
                    'post_pingback'   => (int) ($counts->post_pingback ?? 0),
                ],
            ]);

        // ═════════════════════════════════════════════════════
        //  9. block_comment_word
        // ═════════════════════════════════════════════════════
        case 'block_comment_word':
            $word = sanitize_text_field($params['word'] ?? $params['keyword'] ?? '');
            if (empty($word)) return wpilot_err('Word/phrase to block is required.');

            // WordPress stores blocklist in options (one word/phrase per line)
            $blocklist = get_option('disallowed_keys', '');
            // Also check legacy option name
            if (empty($blocklist)) $blocklist = get_option('blacklist_keys', '');

            $words = array_filter(array_map('trim', explode("\n", $blocklist)));
            if (in_array(strtolower($word), array_map('strtolower', $words))) {
                return wpilot_ok("\"{$word}\" is already in the blocklist.");
            }

            $words[] = $word;
            $updated = implode("\n", $words);
            update_option('disallowed_keys', $updated);

            return wpilot_ok("Added \"{$word}\" to comment blocklist. Comments containing this word will be sent to trash.", [
                'blocklist_count' => count($words),
            ]);

        // ═════════════════════════════════════════════════════
        //  10. configure_comments
        // ═════════════════════════════════════════════════════
        case 'configure_comments':
            $changes = [];

            if (isset($params['enabled'])) {
                $val = filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN) ? 'open' : 'closed';
                update_option('default_comment_status', $val);
                $changes[] = 'comments ' . ($val === 'open' ? 'enabled' : 'disabled');
            }

            if (isset($params['require_approval'])) {
                $val = filter_var($params['require_approval'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                update_option('comment_moderation', $val);
                $changes[] = 'approval ' . ($val === '1' ? 'required' : 'not required');
            }

            if (isset($params['require_name_email'])) {
                $val = filter_var($params['require_name_email'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                update_option('require_name_email', $val);
                $changes[] = 'name/email ' . ($val === '1' ? 'required' : 'optional');
            }

            if (isset($params['close_after_days'])) {
                $days = intval($params['close_after_days']);
                if ($days > 0) {
                    update_option('close_comments_for_old_posts', '1');
                    update_option('close_comments_days_old', $days);
                    $changes[] = "auto-close after {$days} days";
                } else {
                    update_option('close_comments_for_old_posts', '0');
                    $changes[] = 'auto-close disabled';
                }
            }

            if (isset($params['threaded'])) {
                $val = filter_var($params['threaded'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                update_option('thread_comments', $val);
                $changes[] = 'threaded comments ' . ($val === '1' ? 'enabled' : 'disabled');
            }

            if (isset($params['notify_author'])) {
                $val = filter_var($params['notify_author'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                update_option('comments_notify', $val);
                $changes[] = 'author notification ' . ($val === '1' ? 'on' : 'off');
            }

            if (isset($params['notify_moderation'])) {
                $val = filter_var($params['notify_moderation'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                update_option('moderation_notify', $val);
                $changes[] = 'moderation notification ' . ($val === '1' ? 'on' : 'off');
            }

            if (empty($changes)) return wpilot_err('No settings provided. Use: enabled, require_approval, require_name_email, close_after_days, threaded, notify_author, notify_moderation.');

            return wpilot_ok('Comment settings updated: ' . implode(', ', $changes) . '.', ['changes' => $changes]);

        default:
            return null;
    }
}
