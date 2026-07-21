# FastMagento: OpenSearch Serving Layer for Magento 2

## Overview
FastMagento makes OpenSearch the primary **serving layer** for the storefront — products,
category tree, PDP, search and layered navigation are hydrated from OpenSearch instead of
Magento's EAV/ORM, with MySQL kept as the source of truth. Reads are transparent to third
parties (a real `Magento\Catalog\Model\Product` / `Category` is hydrated from the OpenSearch
document), base-Magento only, and cache/Varnish safe.

The goal is to drive product/EAV SQL toward zero on the hot paths. On this catalog: a cold
homepage dropped from ~21,610 to ~1,084 SQL queries; PDP and category pages serve with 0
product/EAV SQL; the search page dropped 342 → 118.

## Features
✅ **OpenSearch product serving** — PDP, PLP and search hydrate real product objects from
OpenSearch (no EAV load), including price, stock, media, tier/catalog-rule prices  
✅ **Category serving layer** — a dedicated `magento2_categories` index powers the mega-menu,
top-nav, breadcrumbs and layered-nav from OpenSearch (no `catalog_category_entity*` reads)  
✅ **All product types** — simple, virtual, **downloadable** (links + samples rendered from
the index), and **configurable** (swatch `jsonConfig` / option prices served from OpenSearch)  
✅ **Real-time stock sync** — order placement, refunds/returns and MSI inventory-API writes
reproject the affected products (and their configurable parents) into the index immediately  
✅ **Read-path resilience** — warm-on-miss (a product absent from the index is added on first
access) and native fallback whenever OpenSearch is unavailable  
✅ **Full Cache / Varnish support** — serving is transparent to FPC and the layout cache

## Indexers
| Indexer id | Index | Serves |
|---|---|---|
| `fastmagento_product` | `magento2_products` | product docs (PDP / PLP / search / cart) |
| `fastmagento_category` | `magento2_categories` | category tree, menu flags, url paths |

Both track incremental changes via `mview` (subscriptions on the respective EAV tables);
stock changes additionally sync in real time (see **Real-time stock sync** above).

```bash
bin/magento indexer:reindex fastmagento_product
bin/magento indexer:reindex fastmagento_category
```

---

# Installation Guide

## **1️⃣ Install the Module**

### **Option 1: Composer Installation (Recommended)**
```bash
composer require parkktech/fastmagento
bin/magento module:enable ParkkTech_FastMagento
bin/magento setup:upgrade
bin/magento cache:flush
```

### **Option 2: Manual Installation**
```bash
mkdir -p app/code/ParkkTech/FastMagento
cp -R /your/local/module/path/* app/code/ParkkTech/FastMagento/
```
Then, enable the module:
```bash
bin/magento module:enable ParkkTech_FastMagento
bin/magento setup:upgrade
bin/magento cache:flush
```

---

## **2️⃣ Configure OpenSearch in Magento Admin**
### Navigate to:
📍 `Stores` ➝ `Configuration` ➝ `FastMagento`

### **Set Up Indexing:**
- ✅ **Enable Real-Time Indexing:** Yes
- ✅ **Enable Cron-Based Indexing:** Yes
- ✅ **Set OpenSearch Host** (Your OpenSearch URL)

### **Search & Filter Configuration:**
- ✅ **Select Search Attributes**
- ✅ **Select Layered Navigation Filters**
- ✅ **Define Sorting Options**
- ✅ **Enable AJAX Search & Filtering**

---

## **3️⃣ Verify OpenSearch Indexing**
### **Run a full reindex:**
```bash
bin/magento indexer:reindex fastmagento_product
bin/magento indexer:reindex fastmagento_category
```
### **Check OpenSearch for a basic search:**
```bash
curl -X GET "http://localhost:9200/magento_products/_search?pretty"
```
✅ **If products appear in JSON format, indexing is working!**

---

### **Additional OpenSearch Commands**

**1) Count Documents**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_count?pretty"      -H 'Content-Type: application/json'      -d '{
       "query": {
         "match_all": {}
       }
     }'
```
Example output:
```json
{
  "count" : 13863,
  "_shards" : {
    "total" : 1,
    "successful" : 1,
    "skipped" : 0,
    "failed" : 0
  }
}
```

**2) View Index Mapping**
```bash
curl -X GET "http://<host>:9200/<index-name>/_mapping?pretty"
curl -X GET "http://localhost:9200/magento2_her_production_products/_mapping?pretty"
```

**3) View All Documents**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_search?pretty&size=10000"      -H "Content-Type: application/json"      -d '{
       "query": {
         "match_all": {}
       }
     }'
```

**4) Retrieve a Single Document by ID**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_doc/965577?pretty"
```
If `_id` is `965577`, the response looks like:
```json
{
  "_index" : "magento2_her_production_products",
  "_id" : "965577",
  "_source" : {
    "entity_id" : 965577,
    "sku" : "nau001-wr43s5",
    ...
  }
}
```

**5) Filter by `entity_id`**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_search?pretty"      -H "Content-Type: application/json"      -d '{
       "query": {
         "term": {
           "entity_id": "965577"
         }
       }
     }'
```

**6) Filter by `sku`**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_search?pretty"      -H "Content-Type: application/json"      -d '{
       "query": {
         "term": {
           "sku": "nau001-wr43s5"
         }
       }
     }'
```

**7) Search Partial SKUs (Wildcard)**
```bash
curl -X GET "http://localhost:9200/magento2_her_production_products/_search?pretty"      -H "Content-Type: application/json"      -d '{
       "query": {
         "wildcard": {
           "sku": "*nau001-wr*"
         }
       }
     }'
```

---

## **4️⃣ Validate Frontend (PLP, PDP, Search)**

- ✅ Ensure products load from OpenSearch
- ✅ Confirm no ORM queries in DevTools (`F12`)
- ✅ Test AJAX filtering & sorting

---

## **5️⃣ Performance & Caching**
```bash
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

---

## 🎉 FastMagento is Now Fully Installed!
✅ **Products, category tree, PDP and search served from OpenSearch**  
✅ **PDP & category pages hit 0 product/EAV SQL; search 342 → 118**  
✅ **Downloadable links/samples and configurable swatches served from the index**  
✅ **Stock stays live on orders, returns and inventory-API writes**  

## Known limitations / roadmap
- **Configurable add-to-cart** through the OpenSearch-hydrated shell is not yet wired
  (`getProductByAttributes` option→child matching); configurable PDPs render fully but the
  add-to-cart handoff needs completion. Simple/virtual/downloadable add and order normally.
- Grouped/bundle read paths are indexed but not yet fully exercised.
- Multi-store: the serving index projects the default store view (per the product index).
