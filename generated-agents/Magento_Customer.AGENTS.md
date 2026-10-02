<!-- agents:module Magento_Customer schema=1 -->
# Magento_Customer — agent guide

`magento/module-customer` · area: global (frontend + adminhtml) · depends: Magento_Eav, Magento_Directory

Customer accounts, addresses, and **customer groups**. Owns authentication and
account management, the customer EAV entity and its attributes, and the session /
visitor / login logs. A large REST surface (42 routes).

## Boundary
- **Owns:** the `customer_entity*` EAV tables, `customer_address_entity*`,
  `customer_group`, customer EAV attribute metadata, account/auth management,
  `customer_visitor` / `customer_log`.
- **Does NOT own:** orders (`Magento_Sales`); carts (`Magento_Quote`); the EAV
  engine itself (`Magento_Eav`); countries/regions/currency (`Magento_Directory`).

## To change behavior here, use these seams (don't edit core classes)
- **Observe:** `customer_save_after_data_object` (UpgradeOrder/QuoteCustomerEmail),
  `customer_customer_authenticated` (CustomerGroupAuthenticate),
  `customer_address_save_before/after`.
- **Plug:** `Api\CustomerRepositoryInterface ← TransactionWrapper` (save is
  transactional); `App\ActionInterface ← CustomerNotification` (post-login
  messages); `Api\GroupRepositoryInterface ← …CustomerGroupExcludedWebsite`.
- **Override preference:** `Api\AccountManagementInterface`,
  `Api\CustomerRepositoryInterface`, `Api\AddressRepositoryInterface`,
  `Api\GroupRepositoryInterface` are DI-bound to their models.
- **Config:** `customer/*` (53 fields, see `inline_docs`).

## Key API (stable contracts)
| Interface | Role |
|---|---|
| `Api\AccountManagementInterface` | create account, authenticate, reset password, confirm |
| `Api\CustomerRepositoryInterface` | load/save/list customers |
| `Api\AddressRepositoryInterface` | customer addresses |
| `Api\GroupRepositoryInterface` / `Api\GroupManagementInterface` | customer groups |
| `Api\CustomerMetadataInterface` / `Api\AddressMetadataInterface` | attribute metadata |

## Wiring (auto-extracted — ground truth)
**Observes (16):** `customer_save_after_data_object` → UpgradeOrderCustomerEmail,
UpgradeQuoteCustomerEmail · `customer_customer_authenticated` → CustomerGroupAuthenticate ·
`customer_address_save_before/after` → Before/AfterAddressSaveObserver ·
`sales_quote_save_after` → BindQuoteCreateObserver (+ more)

**Plugins (26):** `Api\CustomerRepositoryInterface` ← TransactionWrapper ·
`App\ActionInterface` ← CustomerNotification · `Api\GroupRepositoryInterface` ←
the CustomerGroupExcludedWebsite CRUD plugins (+ more)

**Preferences:** 39 — repositories, metadata, group and data interfaces → models.

**Tables:** `customer_entity` (+ `_datetime/_decimal/_int/_text/_varchar` value
tables), `customer_address_entity` (+ value tables), `customer_group`,
`customer_eav_attribute`, `customer_form_attribute`, `customer_visitor`,
`customer_log`, `customer_group_excluded_website`.

**Web API:** 42 routes.  **Cron:** 1.  **GraphQL:** none (see `Magento_CustomerGraphQl`).

## Gotchas / rules
- **Customer is an EAV entity.** Attribute values live in the
  `customer_entity_<type>` value tables, not as columns on `customer_entity`. Add
  attributes through EAV setup (`eav_setup`), never by adding schema columns — and
  read them via the repository/metadata APIs, not raw SQL.
- `CustomerRepositoryInterface` is wrapped by **TransactionWrapper**: a save is one
  transaction, and the `CustomerNotification` plugin surfaces queued messages after
  login. Don't assume a bare model save reproduces repository behavior.
- Customer-group → website **exclusion** is maintained by GroupRepository plugins;
  group changes can recollect quotes (see the Checkout/Quote plugins). Changing
  group logic has cart-pricing side effects.
