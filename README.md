# PropertyFinder Sync & Custom Elementor Real Estate Dropshipping System

An advanced, enterprise-grade WordPress integration system that synchronizes property listings and real estate agents from the official **PropertyFinder Atlas API (v1)** and renders them dynamically using custom Elementor templates, custom post types (CPTs), ACF (Advanced Custom Fields) Pro, and AJAX infinite scroll. 

This system operates similarly to a "dropshipping" model for real estate, where property data is pulled in real-time/on-schedule from a centralized API and displayed as native listings on a custom front-end website with local agents mapped automatically.

---

## 🚀 Key Features

### 1. Robust Core Integration & Sync Engine (`propertyfinder-sync.php`)
* **OAuth 2.0 Access Token Management**: Automatic token generation and secure caching via WordPress Transients (retrieved from `/v1/auth/token` endpoint via Client Credentials grant).
* **Multi-stage Sync Process**:
  * **Agent Synchronization**: Pulls user/agent profiles from PropertyFinder API, maps them to the Houzez CPT (`houzez_agent`), and populates profile fields (first name, last name, phone, WhatsApp, bio, license number, compliance, etc.).
  * **Property Synchronization**: Pulls listings from PropertyFinder API and inserts/updates custom `property` posts.
* **Smart Location Resolver**: 
  * Resolves nested locations dynamically from the API tree.
  * Prioritizes the deepest location node (e.g., Tower or Sub-community) to generate clean, SEO-friendly location titles (e.g., *"Al Fahad Tower 2, Al Fahad Towers"*).
* **Optimized Media Sideloading**:
  * Downloads images from the API to the local WordPress media library asynchronously.
  * **Anti-Duplicate Check**: Scans existing attachments via meta-keys to prevent duplicate image downloads.
  * **Watermark Preservation**: Prefers watermarked imagery for galleries while setting original high-resolution images where appropriate.
  * Integrates directly with Houzez Theme gallery (`fave_property_images`).
* **Relational Mapping (Properties ↔ Agents)**:
  * Uses the API's public profile ID (`pf_user_id`) to automatically link each synced property post to its corresponding agent post.
  * Automatically sets the theme's agent options (`fave_agents`, `fave_agent_display_option`).
* **Cron Scheduling & Manual Triggers**:
  * Supports manual sync trigger through secure query parameters (e.g., `wp-admin/?pf_sync`).
  * Automated background synchronization using a custom-defined weekly WordPress Cron schedule (`pf_auto_sync_event`).

### 2. Custom Shortcodes & Elementor Widgets (`campy-real-estate.code-snippets.json`)
A library of modular, performance-optimized shortcodes developed to render custom API-mapped data seamlessly within Elementor loops and single templates:

* **`[pf_houzez_gallery]` (Lightbox Gallery Grid)**:
  * Generates a modern Houzez-style property image gallery grid (one large primary image, two smaller adjacent thumbnails, and a counter overlay).
  * Bundled with a custom vanilla JavaScript lightbox overlay supporting thumbnails, arrow navigation, and responsive swipe feel.
* **`[price_sale_amount_h2]` & `[price_sale_amount_h4]`**:
  * Formats property price tags dynamically.
  * Handles complex cases: Sale price only, Rent price only, Rent + Sale combined (e.g. rent per year vs. sale total), and "Price on Request".
* **`[property_full_title]`**:
  * Builds dynamic, SEO-rich titles: `[PROPERTY TYPE] [TRANSACTION TYPE] - [PRICE]` (e.g., *APARTMENT FOR SALE - AED 1,200,000*).
  * Automatically includes a Google Maps link targeting the precise coordinates fetched from the API location tree.
* **`[property_location_card]`**:
  * Displays a user-friendly location badge with an icon and a click-to-map redirect button.
* **`[price_insights]`**:
  * Displays comprehensive financial details: Sale price, Rent price per year, down payment requirement, and number of cheques required.
* **`[property_agent_card]`**:
  * Formats a professional author card linking the property to the synced agent's profile.
  * Displays name, position, bio (safely trimmed), verification badge, "Super Agent" status, and dynamic contact actions (Call, LinkedIn).
* **`[property_details_box]`**:
  * A specifications panel populated with property type, category, furnishing status, bed/bath count, size, and availability date, decorated with FontAwesome 6 icons.
* **`[property_amenities_box]`**:
  * Resolves raw API amenity keys (e.g. `central_ac`, `covered_parking`) into human-readable labels with corresponding style icons.
* **`[pf_assigned_agent]`**:
  * A sticky/right-side floating agent contact widget featuring the agent's photo, name, listings link, call button (`tel:`), and direct WhatsApp button (`wa.me/`).
* **`[agent_properties]` (AJAX Infinite Scroll Listings)**:
  * A powerful feature placed on single agent pages.
  * Dynamically queries the custom `property` post-type and loads properties assigned to the agent.
  * Uses **AJAX infinite scroll** to fetch and append properties on-scroll without page reloads, using Elementor templates.
* **`[agent_personal_info]`**:
  * A bio metadata block displaying calculated agent experience (computed from `created_at` date), Broker License (BRN number extracted from compliances data), and verification state.
* **`[property_qr_box]`**:
  * Generates dynamic QR codes in real-time using an external API, embedding the site's logo. Scannable QR redirect link allows mobile users to instantly scan and view the property listing on their mobile devices, showing the property reference number.

---

## 🛠️ Technology Stack
* **PHP (WordPress Plugin Architecture)**: Built as a standalone WordPress sync plugin, hooking into core APIs.
* **WordPress Core & APIs**: Wp_Query, custom taxonomies (`property_category`), cron scheduler, transient caching API, and media sideloading routines.
* **Elementor Pro**: Custom loop templates, single page builders, and theme archive templates.
* **ACF Pro (Advanced Custom Fields)**: Mapped groups, repeaters, select fields, and relation arrays.
* **Vanilla JavaScript & AJAX**: Handles the front-end gallery lightbox and dynamic infinite scroll for agent property cards.
* **JSON REST API Integration**: Calls PropertyFinder Atlas API endpoints with OAuth2 token validation.

---

## 📂 Codebase Structure
* `propertyfinder-sync (4).php`: The core plugin file containing configuration, authentication, location resolver, sideload engine, agent sync, property sync, relationship mapping, and WP-Cron registration.
* `campy-real-estate.code-snippets (1).json`: Exported snippet definitions featuring all custom shortcodes, JS scripts, and custom taxonomy structures.

---

## ⚙️ Installation & Configuration
1. **Plugin Installation**:
   * Upload `propertyfinder-sync (4).php` to your `wp-content/plugins/` directory and activate **PropertyFinder Sync**.
   * Replace `PF_API_KEY`, `PF_API_SECRET`, and `PF_BROKER_ID` inside the plugin file with your real credentials.
2. **ACF Import**:
   * Configure ACF fields matching the keys queried in the plugin (e.g. `pf_property_id`, `reference`, `price` group, `location` group, `assigned_to` group, etc.).
3. **Shortcode Snippets**:
   * Import the JSON file `campy-real-estate.code-snippets (1).json` into the **Code Snippets** WordPress plugin and activate all scripts.
4. **Elementor templates**:
   * Create dynamic layouts for Single Property pages, Single Agent profiles, and Archive templates using Elementor. Insert the respective shortcodes (e.g. `[pf_houzez_gallery]`, `[pf_assigned_agent]`, `[agent_properties]`) in the desired layout locations.
5. **Sync Trigger**:
   * Trigger the initial sync manually by navigating to `https://yourdomain.com/?pf_sync` while logged in as Administrator. Afterwards, the WP-Cron schedule will keep the inventory updated weekly.
