# Menu Admin Management V2

## Menu Mapping
- menu_a -> menu.html
- menu_b -> cocktail.html (legacy typo reference: cockatail.html)
- menu_c -> namastemenu.html

## Backend Architecture
- Controller: app/Controllers/MenuManagementController.php
- Service: app/Services/MenuManagementService.php
- Repository: app/Repositories/MenuManagementRepository.php
- Router wiring: app/Routes/ActionRouter.php
- XLSX parser/writer: app/Support/SimpleXlsx.php

## Migration Files
- database/migrations/003_menu_management_v2.sql

Created tables:
- menu_items_v2
- menu_item_variants_v2
- menu_categories_v2
- menu_import_snapshots_v2

## Auth and Permission Gate
All new actions are guarded in ActionRouter with:
- token auth required
- role admin/superadmin (via existing permission middleware model)
- permission required: menuEditor

## New Action Routing Map
Editor actions:
- admin_menu_editor_load (GET)
- admin_menu_editor_save_changes (POST)
- admin_menu_editor_add_row (POST)
- admin_menu_editor_delete_rows (POST)
- admin_menu_editor_set_visibility (POST)

Designer actions:
- admin_menu_designer_load (GET)
- admin_menu_designer_save_category_order (POST)
- admin_menu_designer_save_item_order (POST)
- admin_menu_designer_toggle_category (POST)
- admin_menu_designer_toggle_item (POST)
- admin_menu_designer_clone_category (POST)

Import/export/template actions:
- admin_menu_import_preview (POST, multipart)
- admin_menu_import_execute (POST)
- admin_menu_export (GET)
- admin_menu_template (GET)

## Frontend Modules Updated
- asianwokandgrill.in/js/admin-modules/api-client.js
- asianwokandgrill.in/js/admin-modules/menu-editor.js
- asianwokandgrill.in/js/admin-modules/menu-category-designer.js
- asianwokandgrill.in/js/admin-modules/data-import.js

The existing shell pages continue to host these modules:
- asianwokandgrill.in/admin-menu-price-editor.html
- asianwokandgrill.in/admin-menu-category-designer.html

## Old -> New File Map
- app/Services/MenuService.php -> app/Services/MenuManagementService.php (new menu management domain)
- app/Repositories/MenuRepository.php -> app/Repositories/MenuManagementRepository.php (new v2 table access)
- app/Controllers/MenuController.php -> app/Controllers/MenuManagementController.php (new admin actions)
- asianwokandgrill.in/js/admin-modules/menu-editor.js -> replaced with v2 editor behavior
- asianwokandgrill.in/js/admin-modules/menu-category-designer.js -> replaced with v2 designer behavior
- asianwokandgrill.in/js/admin-modules/data-import.js -> replaced with v2 Excel flow

## Old -> New Action Map
- menu_admin_snapshot -> admin_menu_editor_load / admin_menu_designer_load
- menu_admin_save_snapshot -> admin_menu_editor_save_changes
- menu_admin_save_category_order -> admin_menu_designer_save_category_order
- menu_admin_import -> admin_menu_import_preview + admin_menu_import_execute
- menu_admin_export -> admin_menu_export

## Diet Classification Rules Implemented
Service methods:
- classifyPrimaryDiet(...)
- hasAnyNonVegPrice(...)
- isUniversalCategory(...)

Rules applied during:
- editor save normalization
- import execute normalization

Primary diet outcomes:
- jain
- nonveg
- veg
- mixed
- universal
- bar (menu_b)
- empty string fallback

## Import Preview Contract
admin_menu_import_preview response fields:
- ok
- menuType
- sheet_found
- tmpPath
- total_rows
- data_rows
- blank_rows_skipped
- mapped_columns
- unmapped_columns
- sample_rows
- categories
- variant_columns
- previewSummary

## Import Execute Contract
admin_menu_import_execute response fields:
- ok
- type
- inserted
- updated
- skipped
- warnings
- errors

## Export/Template Contract
admin_menu_export and admin_menu_template return:
- ok
- fileName
- mimeType
- base64

Frontend decodes base64 and downloads .xlsx.

## Sample Requests
Editor load:
GET /?action=admin_menu_editor_load&menuType=menu_a

Editor save:
POST / with JSON:
{
  "action": "admin_menu_editor_save_changes",
  "menuType": "menu_a",
  "changes": [
    {
      "id": 1,
      "category": "Appetizers",
      "itemName": "Paneer Chilli",
      "isAvailable": true,
      "priceVeg": 249,
      "variants": [
        { "variantLabel": "Half", "price": 149 },
        { "variantLabel": "Full", "price": 249 }
      ]
    }
  ]
}

Import preview multipart:
POST / with FormData:
- action=admin_menu_import_preview
- menuType=menu_a
- file=<xlsx>

Import execute:
POST / with JSON:
{
  "action": "admin_menu_import_execute",
  "menuType": "menu_a",
  "tmpPath": "menu-import-<token>.json",
  "createCategories": true,
  "takeSnapshot": true
}

Export:
GET /?action=admin_menu_export&menuType=menu_a

Template:
GET /?action=admin_menu_template&menuType=menu_a
