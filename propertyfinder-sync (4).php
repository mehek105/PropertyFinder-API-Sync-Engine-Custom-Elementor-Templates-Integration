<?php
/*
Plugin Name: PropertyFinder Sync (FINAL v2.2 â€“ AGENT MAPPING FIXED)
Description: Full Agent + Property Sync with ACF Mapping, Location & Agent relations fixed. Enhanced Agent Contact Info for Property Pages.
Version: 2.2
Author: Mehek Babar & Manus
*/

defined('ABSPATH') || exit;

/* ======================
LOAD MEDIA
====================== */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/* ======================
API CONFIG
====================== */
define('PF_API_KEY', 'your api');
define('PF_API_SECRET', 'api secret');
define('PF_BROKER_ID', 4146);

/* ======================
CPTs
====================== */
add_action('init', function () {
    register_post_type('property', [
        'label' => 'Properties',
        'public' => true,
        'supports' => ['title', 'editor', 'thumbnail'],
        'show_in_rest' => true
    ]);
});

/* ======================
TOKEN
====================== */
function pf_get_token()
{
    $token = get_option('pf_token');
    $exp   = get_option('pf_token_expiry');

    if ($token && time() < $exp) return $token;

    $res = wp_remote_post('https://atlas.propertyfinder.com/v1/auth/token', [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'apiKey' => PF_API_KEY,
            'apiSecret' => PF_API_SECRET
        ])
    ]);

    if (is_wp_error($res)) return false;

    $body = json_decode(wp_remote_retrieve_body($res), true);

    update_option('pf_token', $body['accessToken']);
    update_option('pf_token_expiry', time() + $body['expiresIn']);

    return $body['accessToken'];
}
function pf_sideload_image_optimized($image_url, $post_id)
{

    if (empty($image_url)) return false;

    // already exists?
    $filename = basename($image_url);

    $existing = get_posts([
        'post_type'  => 'attachment',
        'meta_key'   => '_imported_image',
        'meta_value' => $filename,
        'numberposts' => 1
    ]);

    if ($existing) {
        return $existing[0]->ID;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) return false;

    $file = [
        'name'     => $filename,
        'tmp_name' => $tmp
    ];

    $att_id = media_handle_sideload($file, $post_id);

    if (is_wp_error($att_id)) {
        @unlink($tmp);
        return false;
    }

    update_post_meta($att_id, '_imported_image', $filename);

    return $att_id;
}

/* ======================
LOCATION RESOLVER
====================== */
function pf_get_location_full($id, $token)
{
    if (!$id) return [];

    $cache = get_transient('pf_loc_full_' . $id);
    if ($cache) return $cache;

    $res = wp_remote_get(
        'https://atlas.propertyfinder.com/v1/locations?search=al&ids[]=' . intval($id),
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ]
        ]
    );

    if (is_wp_error($res)) return [];

    $body = json_decode(wp_remote_retrieve_body($res), true);
    if (empty($body['data'][0])) return [];

    $loc = $body['data'][0];

    /* ===== TREE (Repeater safe mapping) ===== */
    $tree_rows = [];

    foreach ($loc['tree'] ?? [] as $t) {
        $tree_rows[] = [
            'tree_location_id' => $t['id'] ?? '',
            'tree_name'        => $t['name'] ?? '',
            'tree_type'        => $t['type'] ?? '',
        ];
    }
    $mapped = [
        'location_id'   => $loc['id'] ?? '',
        'location_name' => $loc['pathName'] ?? $loc['name'] ?? '',
        'coordinates'   => [
            'lat' => $loc['coordinates']['lat'] ?? '',
            'lng' => $loc['coordinates']['lng'] ?? '',
        ],
        'tree' => $tree_rows,
    ];

    set_transient('pf_loc_full_' . $id, $mapped, DAY_IN_SECONDS);

    return $mapped;
}
/**
 * Resolve best location ID from listing
 * Priority: deepest tree node (Tower / Subcommunity)
 */
function pf_resolve_location_id($listing)
{
    if (!empty($listing['location']['tree']) && is_array($listing['location']['tree'])) {
        $last = end($listing['location']['tree']);
        if (!empty($last['id'])) {
            return (int) $last['id'];
        }
    }

    return !empty($listing['location']['id'])
        ? (int) $listing['location']['id']
        : 0;
}


/**
 * Build PropertyFinder-style location title from location tree
 * Example: "Al Fahad Tower 2, Al Fahad Towers"
 */
function pf_build_location_title_from_tree($location)
{
    if (empty($location['tree']) || !is_array($location['tree'])) {
        return $location['location_name'] ?? '';
    }

    $tree = array_reverse($location['tree']);

    $primary   = '';
    $secondary = '';

    foreach ($tree as $node) {

        if (
            !$primary &&
            !empty($node['tree_name']) &&
            in_array($node['tree_type'], ['TOWER', 'SUBCOMMUNITY'], true)
        ) {
            $primary = $node['tree_name'];
            continue;
        }

        if (
            $primary &&
            !empty($node['tree_name']) &&
            in_array($node['tree_type'], ['SUBCOMMUNITY', 'COMMUNITY'], true)
        ) {
            $secondary = $node['tree_name'];
            break;
        }
    }

    if ($primary && $secondary) {
        return $primary . ', ' . $secondary;
    }

    return $primary ?: ($location['location_name'] ?? '');
}

/* ======================
AGENTS SYNC (FULL WITH EXTRA FIELDS)
====================== */
function pf_sync_agents_full()
{
    $token = pf_get_token();
    if (!$token) return;

    $res = wp_remote_get(
        'https://atlas.propertyfinder.com/v1/users',
        ['headers' => ['Authorization' => 'Bearer ' . $token]]
    );

    if (is_wp_error($res)) return;

    $data = json_decode(wp_remote_retrieve_body($res), true);

    foreach ($data['data'] ?? [] as $a) {

        // Find existing agent by Agent ID
        $existing = get_posts([
            'post_type'      => 'houzez_agent',
            'meta_key'       => 'pf_agent_id',
            'meta_value'     => $a['id'],
            'fields'         => 'ids',
            'posts_per_page' => 1
        ]);

        $name = trim(($a['firstName'] ?? '') . ' ' . ($a['lastName'] ?? ''));

        // Create or update post
        $aid = $existing ? $existing[0] : wp_insert_post([
            'post_type'   => 'houzez_agent',
            'post_title'  => $name,
            'post_status' => 'publish'
        ]);

        // Public Profile Data
        $p = $a['publicProfile'] ?? [];

        // ===============================
        // SAVE AGENT ID (ROOT)
        // ===============================
        $agent_id = $a['id'] ?? '';
        update_field('pf_agent_id', $agent_id, $aid);
        update_post_meta($aid, 'pf_agent_id', $agent_id);

        // ===============================
        // SAVE PUBLIC PROFILE ID (pf_user_id)
        // ===============================
        $public_profile_id = $p['id'] ?? '';

        if (!empty($public_profile_id)) {
            update_field('pf_user_id', $public_profile_id, $aid);
            update_post_meta($aid, 'pf_user_id', $public_profile_id);
        }

        // DEBUG LOG
        error_log("PF SYNC => Post {$aid} | Agent ID = {$agent_id} | PublicProfile ID = {$public_profile_id}");

        // ===============================
        // BASIC INFO
        // ===============================
        update_field('first_name', $a['firstName'] ?? '', $aid);
        update_field('last_name', $a['lastName'] ?? '', $aid);
        update_field('email', $a['email'] ?? '', $aid);
        update_field('mobile', $a['mobile'] ?? '', $aid);
        update_field('created_at', $a['createdAt'] ?? '', $aid);

        // ===============================
        // HOUZEZ META
        // ===============================
        update_post_meta($aid, 'fave_agent_email', $a['email'] ?? '');
        update_post_meta($aid, 'fave_agent_mobile', $a['mobile'] ?? '');
        update_post_meta($aid, 'fave_agent_phone', $p['phone'] ?? '');
        update_post_meta($aid, 'fave_agent_whatsapp', $p['whatsappPhone'] ?? '');
        update_post_meta($aid, 'fave_agent_description', $p['bio']['primary'] ?? '');
        update_post_meta($aid, 'fave_agent_position', $p['position']['primary'] ?? '');
        update_post_meta($aid, 'fave_agent_status', $a['status'] ?? '');

        // ===============================
        // PUBLIC PROFILE ACF
        // ===============================
        update_field('phone', $p['phone'] ?? '', $aid);
        update_field('phone_secondary', $p['phoneSecondary'] ?? '', $aid);
        update_field('whatsapp', $p['whatsappPhone'] ?? '', $aid);
        update_field('bio', $p['bio']['primary'] ?? '', $aid);
        update_field('position', $p['position']['primary'] ?? '', $aid);
        update_field('is_super_agent', !empty($p['isSuperAgent']) ? 1 : 0, $aid);
        update_field('linkedin', $p['linkedinAddress'] ?? '', $aid);
        update_field('status', $a['status'] ?? '', $aid);

        // ===============================
        // ROLE
        // ===============================
        if (!empty($a['role']) && is_array($a['role'])) {
            $r = $a['role'];
            update_field('roles', [[
                'id'            => $r['id'] ?? '',
                'name'          => $r['name'] ?? '',
                'role_key'      => $r['roleKey'] ?? '',
                'base_role_key' => $r['baseRoleKey'] ?? '',
                'is_custom'     => !empty($r['isCustom']) ? 1 : 0
            ]], $aid);
        }

        // ===============================
        // COMPLIANCES
        // ===============================
        if (!empty($p['compliances'])) {
            $rows = [];
            foreach ($p['compliances'] as $c) {
                $rows[] = [
                    'type'        => $c['type'] ?? '',
                    'value'       => $c['value'] ?? '',
                    'status'      => $c['status'] ?? '',
                    'expiry_date' => $c['expiryDate'] ?? '',
                    'reason'      => $c['reason'] ?? ''
                ];
            }
            update_field('compliances', $rows, $aid);
        }

        // ===============================
        // VERIFICATION
        // ===============================
        update_field('verification_status', $p['verification']['status'] ?? '', $aid);
        update_field('verification_request_date', $p['verification']['requestDate'] ?? '', $aid);

        // ===============================
        // IMAGE
        // ===============================
        if (!has_post_thumbnail($aid) && !empty($p['imageVariants']['large']['jpg'])) {
            $img = media_sideload_image($p['imageVariants']['large']['jpg'], $aid, null, 'id');
            if (!is_wp_error($img)) {
                set_post_thumbnail($aid, $img);
            }
        }
    }
}



/* ======================
PROPERTIES SYNC
====================== */
function pf_sync_properties()
{
    // Agents first
    pf_sync_agents_full();

    $token = pf_get_token();
    if (!$token) return;

    $page = 1;

    do {


        $res = wp_remote_get(
            "https://atlas.propertyfinder.com/v1/listings?brokerId=" . PF_BROKER_ID . "&page=$page",
            ['headers' => ['Authorization' => 'Bearer ' . $token]]
        );

        if (is_wp_error($res)) break;

        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (empty($data['results'])) break;

        foreach ($data['results'] as $l) {

            /* ======================
            CREATE / UPDATE POST
            ======================= */
            // HARD DUPLICATE PROTECTION
            $existing = get_posts([
                'post_type'  => 'property',
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key'   => 'pf_property_id',
                        'value' => $l['id'],
                        'compare' => '='
                    ],
                    [
                        'key'   => 'reference',
                        'value' => $l['reference'] ?? '',
                        'compare' => '='
                    ]
                ],
                'fields' => 'ids',
                'posts_per_page' => 1
            ]);

            $pid = $existing ? $existing[0] : wp_insert_post([
                'post_type' => 'property',
                'post_title' => $l['title']['en'] ?? 'Property',
                'post_content' => $l['description']['en'] ?? '',
                'post_status' => 'publish'
            ]);

            /* ======================
            BASIC FIELDS
            ======================= */
            update_field('pf_property_id', $l['id'], $pid);
            update_field('reference', $l['reference'] ?? '', $pid);
            update_field('type', $l['type'] ?? '', $pid);
            update_field('category', $l['category'] ?? '', $pid);
            update_field('project_status', $l['projectStatus'] ?? '', $pid);
            update_field('furnishing_type', $l['furnishingType'] ?? '', $pid);
            update_field('uae_emirate', $l['uaeEmirate'] ?? '', $pid);
            update_field('unit_number', $l['unitNumber'] ?? '', $pid);
            update_field('bedrooms', (int)($l['bedrooms'] ?? 0), $pid);
            update_field('bathrooms', (int)($l['bathrooms'] ?? 0), $pid);
            update_field('number_of_floors', (int)($l['numberOfFloors'] ?? 0), $pid);
            update_field('size', (int)($l['size'] ?? 0), $pid);
            update_field('plot_size', (int)($l['plotSize'] ?? 0), $pid);
            update_field('available_from', $l['availableFrom'] ?? '', $pid);
            update_field('created_at', $l['createdAt'] ?? '', $pid);
            update_field('updated_at', $l['updatedAt'] ?? '', $pid);

            /* ======================
            AMENITIES (Checkbox)
            ======================= */
            if (!empty($l['amenities']) && is_array($l['amenities'])) {
                update_field('amenities', array_values($l['amenities']), $pid);
            }

            /* ======================
            DESCRIPTION (GROUP)
            ======================= */
            if (!empty($l['description'])) {
                update_field('description', [
                    'en' => $l['description']['en'] ?? '',
                    'ar' => $l['description']['ar'] ?? ''
                ], $pid);
            }

            /* ======================
            TITLE (GROUP)
            ======================= */
            if (!empty($l['title'])) {
                update_field('title', [
                    'en' => $l['title']['en'] ?? '',
                    'ar' => $l['title']['ar'] ?? ''
                ], $pid);
            }

            /* ======================
            LOCATION (GROUP)
            ======================= */
            $location_id = pf_resolve_location_id($l);

            if ($location_id) {
                update_field('location', [
                    'id' => $location_id
                ], $pid);

                $location = pf_get_location_full($location_id, $token);
                if (!empty($location)) {
                    update_field('location', $location, $pid);
                }
            }

            /* =======================
            ASSIGNED TO (GROUP) - AGENT MAPPING
            ======================= */

            if (!empty($l['assignedTo'])) {

                // assignedTo Public Profile ID
                $assigned_pf_user_id = (string) ($l['assignedTo']['id'] ?? '');

                // Thumbnail URL from API
                $agent_thumbnail_url = $l['assignedTo']['photos']['thumbnail']['url'] ?? '';



                // Find Agent using pf_user_id
                $agent_post = get_posts([
                    'post_type'      => 'houzez_agent',
                    'meta_key'       => 'pf_user_id', // correct mapping
                    'meta_value'     => $assigned_pf_user_id,
                    'fields'         => 'ids',
                    'posts_per_page' => 1
                ]);

                // Base group data
                $group_data = [
                    'id'        => $assigned_pf_user_id,
                    'name'      => $l['assignedTo']['name'] ?? '',
                    'thumbnail' => $agent_thumbnail_url, // ✅ URL field saved here
                ];

                // If Agent Found
                if (!empty($agent_post) && is_numeric($agent_post[0])) {

                    $agent_id = (int) $agent_post[0];

                    // Pull agent info
                    $group_data['agent_phone'] = get_post_meta($agent_id, 'fave_agent_mobile', true)
                        ?: get_post_meta($agent_id, 'fave_agent_phone', true);

                    $group_data['agent_whatsapp'] = get_post_meta($agent_id, 'fave_agent_whatsapp', true);
                    $group_data['agent_position'] = get_post_meta($agent_id, 'fave_agent_position', true);
                    $group_data['agent_email'] = get_post_meta($agent_id, 'fave_agent_email', true)
                        ?: get_post_meta($agent_id, 'email', true);

                    $group_data['agent_post']     = $agent_id;

                    // Houzez assignment
                    update_post_meta($pid, 'fave_agent_display_option', 'agent_info');
                    update_post_meta($pid, 'fave_agents', [$agent_id]);
                }

                // Save assignedTo group field
                update_field('assigned_to', $group_data, $pid);
            }



            /* ======================
            CREATED BY (GROUP)
            ======================= */
            if (!empty($l['createdBy'])) {
                update_field('created_by', [
                    'id'   => $l['createdBy']['id'] ?? '',
                    'name' => $l['createdBy']['name'] ?? ''
                ], $pid);
            }

            /* ======================
            UPDATED BY (GROUP)
            ======================= */
            if (!empty($l['updatedBy'])) {
                update_field('updated_by', [
                    'id'   => $l['updatedBy']['id'] ?? '',
                    'name' => $l['updatedBy']['name'] ?? ''
                ], $pid);
            }

            /* ======================
            MEDIA (GROUP + REPEATER)
            ======================= */
            /* ======================
            OPTIMIZED MEDIA SYNC (No Duplicates)
            ======================= */
            $media_rows = [];
            $gallery_ids = [];

            foreach ($l['media']['images'] ?? [] as $index => $img) {
                $original_url = $img['original']['url'] ?? '';
                $watermarked_url = $img['watermarked']['url'] ?? '';

                // Use watermarked if available, else original
                $target_url = !empty($watermarked_url) ? $watermarked_url : $original_url;

                if (!empty($target_url)) {
                    // Sideload with duplicate check
                    $attachment_id = pf_sideload_image_optimized($target_url, $pid, $l['title']['en'] ?? '');

                    if ($attachment_id) {
                        // Set the first image as the featured image
                        if ($index === 0) {
                            set_post_thumbnail($pid, $attachment_id); // ALWAYS set first image as featured
                        } else {
                            $gallery_ids[] = $attachment_id;
                        }


                        // Also keep the ACF mapping for reference
                        $media_rows[] = [
                            'original_url'    => esc_url_raw($original_url),
                            'watermarked_url' => esc_url_raw($watermarked_url),
                            'attachment_id'   => $attachment_id // Added for internal use
                        ];
                    }
                }
            }

            // Update ACF Repeater
            if (!empty($media_rows)) {
                update_field('media', ['images' => $media_rows], $pid);
            }

            // ðŸ”¥ Houzez Gallery Integration
            // Houzez stores gallery images in 'fave_property_images' meta as a comma-separated list or array
            if (!empty($gallery_ids)) {
                update_post_meta($pid, 'fave_property_images', $gallery_ids);
            }

            /* ======================
            PORTALS (GROUP)
            ======================= */
            if (!empty($l['portals']['propertyfinder'])) {
                update_field('portals', [
                    'propertyfinder' => [
                        'is_live'     => !empty($l['portals']['propertyfinder']['isLive']) ? 1 : 0,
                    ]
                ], $pid);
            }

            /* ======================
            PRICE (GROUP) – FIXED
            ======================= */

            if (!empty($l['price'])) {

                $old_price = get_field('price', $pid) ?: [];

                $price_type = $l['price']['type'] ?? ($old_price['type'] ?? '');
                $amounts    = $l['price']['amounts'] ?? [];

                // Keep old values by default
                $sale_amount = $old_price['sale_amount'] ?? '';
                $rent_amount = $old_price['rent_amount'] ?? '';
                $cheques     = $old_price['number_of_cheques'] ?? '';

                // Update only if API provides value
                if ($price_type === 'sale' && !empty($amounts['sale'])) {
                    $sale_amount = $amounts['sale'];
                }

                if ($price_type === 'rent' && !empty($amounts['rent'])) {
                    $rent_amount = $amounts['rent'];
                }

                if ($price_type === 'yearly' && !empty($amounts['yearly'])) {
                    $rent_amount = $amounts['yearly'];
                }

                if (!empty($l['price']['numberOfCheques'])) {
                    $cheques = $l['price']['numberOfCheques'];
                }

                update_field('price', [
                    'type'              => $price_type,
                    'sale_amount'       => $sale_amount,
                    'rent_amount'       => $rent_amount,
                    'downpayment'       => $l['price']['downpayment'] ?? ($old_price['downpayment'] ?? ''),
                    'number_of_cheques' => $cheques,
                    'on_request'        => !empty($l['price']['onRequest']) ? 1 : ($old_price['on_request'] ?? 0),
                ], $pid);
            }



            /* ======================
            QUALITY SCORE + DETAILS
            ======================= */
            if (!empty($l['qualityScore'])) {
                update_field('quality_score', [
                    'color' => $l['qualityScore']['color'] ?? '',
                    'value' => (int) ($l['qualityScore']['value'] ?? 0),
                ], $pid);
            }

            $quality_rows = [];
            foreach ($l['qualityScore']['details'] ?? [] as $key => $q) {
                $quality_rows[] = [
                    'key'     => (string) $key,
                    'group'   => $q['group'] ?? '',
                    'color'   => $q['color'] ?? '',
                    'tag'     => $q['tag'] ?? '',
                    'tag_ar'  => $q['tagAr'] ?? '',
                    'value'   => isset($q['value']) ? (int) $q['value'] : 0,
                    'weight'  => isset($q['weight']) ? (int) $q['weight'] : 0,
                    'help'    => $q['help'] ?? '',
                    'help_ar' => $q['helpAr'] ?? '',
                ];
            }
            if (!empty($quality_rows)) {
                update_field('quality_details', $quality_rows, $pid);
            }

            /* ======================
            STATE (GROUP)
            ======================= */
            if (!empty($l['state'])) {
                update_field('state', [
                    'stage' => $l['state']['stage'] ?? '',
                    'type'  => $l['state']['type'] ?? ''
                ], $pid);
            }
        }

        $page++;
    } while ($page <= ($data['pagination']['totalPages'] ?? 1));
}

/* ======================
MANUAL TRIGGER
====================== */
add_action('admin_init', function () {
    if (isset($_GET['pf_sync']) && current_user_can('manage_options')) {
        pf_sync_properties();
        wp_die(' PropertyFinder Sync Complete');
    }
});

// Add weekly schedule
add_filter('cron_schedules', function ($schedules) {
    $schedules['weekly'] = [
        'interval' => 604800, // 7 days
        'display'  => 'Once Weekly'
    ];
    return $schedules;
});

// Clear old hourly event
if (wp_next_scheduled('pf_auto_sync_event')) {
    wp_clear_scheduled_hook('pf_auto_sync_event');
}

// Schedule weekly
if (!wp_next_scheduled('pf_auto_sync_event')) {
    wp_schedule_event(time(), 'weekly', 'pf_auto_sync_event');
}
