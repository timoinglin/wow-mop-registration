# In-Game Shop — Battle Pay Schema Reference

> Part of the **[WoW MoP Registration Portal](../README.md)** documentation — the reverse-engineered `battle_pay_*` schema behind the in-game Shop management.
>
> [↑ Back to the README](../README.md) · [Docs index](../README.md#documentation)

---

Reverse-engineered and verified live against an EmuCoach MoP 7.1 repack (MySQL 8.0.30). This is exactly what `/admin_shop` reads and writes, and what you'd need to debug or hand-edit the store. **All five tables live in the `world` database.**

## The 5 tables that drive the shop

**`battle_pay_group`** — left-sidebar categories
- `id` int unsigned (manual, **NOT** auto-increment) · `idx` (sidebar order) · `name` varchar(16) **hard limit** · `icon` int (client FileDataID) · `type` tinyint (0 = normal, 1 = special banner — use 0)

**`battle_pay_product`** — the purchasable product + price
- `id` (manual) · `title` varchar(50) · `description` varchar(500) · `icon` int (tile FileDataID) · `price` int unsigned ← **the in-game price** · `discount` · `displayId` (3D model; 0 = icon only) · `type` · `choiceType` (1 for single-item) · `flags` (47 = typical store mount) · `flagsInfo`
- Price edit = `UPDATE battle_pay_product SET price = ? WHERE id = ?`

**`battle_pay_product_items`** — which game item(s) the product grants
- `id` (manual) · `itemId` (→ `item_template.entry`, validate) · `count` (1 for mounts/pets) · `productId` (→ `battle_pay_product.id`)

**`battle_pay_entry`** — places a product as a tile in a category (this row is what makes it **visible** in-game)
- `id` (manual) · `groupId` (→ group.id) · `productId` (→ product.id) · `idx` (tile order) · `title` · `description` · `icon` · `displayId` · `banner` tinyint (2 for mount tiles) · `flags`

**`battle_pay_boost_items`** — character-boost bundles only; ignore for normal CRUD.

## Relationship chain

```
battle_pay_group.id              --<  battle_pay_entry.groupId
battle_pay_entry.productId        -->  battle_pay_product.id
battle_pay_product.id            --<  battle_pay_product_items.productId
battle_pay_product_items.itemId   -->  item_template.entry
```

## Verified full-shop JOIN

```sql
SELECT g.name AS category, g.idx, e.id AS entry_id, e.title AS tile,
       p.id AS product_id, p.price, pi.itemId, it.name AS item_name
FROM battle_pay_group g
JOIN battle_pay_entry e          ON e.groupId    = g.id
JOIN battle_pay_product p        ON p.id         = e.productId
JOIN battle_pay_product_items pi ON pi.productId = p.id
JOIN item_template it            ON it.entry     = pi.itemId
ORDER BY g.idx, e.idx;
```

## Operations

- **Add category:** `INSERT` group (id, idx, name, icon, type=0)
- **Edit / delete category:** `UPDATE` / `DELETE` group; delete cascades its entries (+ optional orphan cleanup)
- **Add item (the triple):** `INSERT` product → `INSERT` product_items → `INSERT` entry
- **Change price:** `UPDATE` product.price
- **Delete item:** `DELETE` entry → `DELETE` product + product_items
- **Reorder:** `UPDATE` idx on group (sidebar) or entry (within a category)
- Known-good single-item mount/pet values: `product: type=0, choiceType=1, flags=47, flagsInfo=0, discount=0` · `entry: banner=2, flags=0, icon=product.icon` · `product_items: count=1`

## Critical gotchas

1. **No auto-increment** — compute ids manually. The portal uses a reserved range **≥ 9000** (`SELECT COALESCE(MAX(id),0)+1`).
2. **Changes need a worldserver restart** — these tables are read into memory at startup only; there is no in-game reload. ~1–2 min downtime, disconnects players. `/admin_shop` shows a persistent "restart required" banner after any write.
3. **Operate on `world` only** — never touch `*_main` backup DBs.
4. **Repack-update risk** — a repack update may overwrite custom rows; ids ≥ 9000 minimise collisions, but plan an export/re-seed.
5. **Validation** — name ≤ 16, title ≤ 50, description ≤ 500; verify `itemId` exists in `item_template`; no duplicate ids; price is an unsigned int. (The player's Battle Coins balance lives in `account`, outside these tables.)

---

[↑ Back to the README](../README.md)
