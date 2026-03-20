# Architecture: statsbestproducts

## Purpose

A PrestaShop statistics module that ranks individual products by quantity sold, total revenue, and page views to identify top-performing items in the catalogue.

## Directory Structure

```
statsbestproducts.php   - Module class (ModuleGrid subclass); all business logic
upgrade/                - Migration scripts for version upgrades
tests/                  - PHPUnit test stubs and PHPStan bootstrap
translations/           - Locale string overrides
```

## Key Design Decisions

- **ModuleGrid inheritance**: Uses PrestaShop's built-in grid with column sorting, paging, and CSV export.
- **Product attribute aggregation**: Groups by product (not attribute variant) for a top-level view.

## Extension Points

- Override `getData()` to change ranking metrics or add variant-level detail.
- Extend the `$columns` array to surface additional product attributes.

## Dependency Flow

```
statsbestproducts (ModuleGrid)
  └─> hookDisplayAdminStatsModules() — renders the product ranking grid
  └─> getData()                      — executes product ranking SQL
        └─> Db::getInstance()        — PrestaShop database abstraction
```
