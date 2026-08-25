# DishNet Plugin — Emergency Recovery Guide

**BOOKMARK THIS PAGE. Read before you ever need it.**

This guide gets the plugin working again without a full server restore.
A full DigitalOcean/server restore should NEVER be necessary for a plugin issue.

---

## Option 1 — Emergency Repair URL (fastest, no SSH needed)

Access this URL in your browser while logged into UCRM:

```
https://crm.dishnetafrica.com/crm/_plugins/dishnet-hybrid-telecom/public.php?page=emergency_repair&key=DISHNET_REPAIR
```

This page runs BEFORE the plugin initializes — it works even if the plugin
is completely broken. It will:
1. Delete stale WAL/SHM files that cause crashes
2. Test the database health
3. Tell you if the plugin should work now

**To change the key:** Edit `data/emergency_repair_key.txt` on the server.
Do not share this URL publicly.

After running the repair URL, refresh the UCRM plugin page.

---

## Option 2 — Upload new plugin ZIP via UCRM

**Important:** Do this in UCRM's admin panel, NOT inside the broken plugin.

1. Go to: `https://crm.dishnetafrica.com/crm/billing/plugin`
   (or: UCRM Menu → System → Plugins)
2. Find "DishNet Hybrid Telecom"
3. Click the plugin name → **Edit** → **Upload new version**
4. Upload the latest plugin ZIP

The UCRM plugin management page is **completely separate** from the plugin itself.
Even if the plugin shows "System Error", the UCRM admin panel works fine.

---

## Option 3 — SSH Fix (when UCRM web interface is also broken)

SSH into your DigitalOcean server:

```bash
ssh root@YOUR_SERVER_IP
```

### Step 1: Fix the WAL files (most common cause of crashes)

```bash
# Find the plugin data directory
ls /data/ucrm/data/plugins/dishnet-hybrid-telecom/data/

# Delete stale WAL files (safe — no data loss)
rm -f /data/ucrm/data/plugins/dishnet-hybrid-telecom/data/plugin.sqlite3-wal
rm -f /data/ucrm/data/plugins/dishnet-hybrid-telecom/data/plugin.sqlite3-shm

echo "WAL files cleared"
```

After this, refresh the UCRM plugin page. It should work.

### Step 2: Replace plugin files via SSH (if WAL fix doesn't work)

```bash
# Upload the new plugin ZIP from your computer
# Run this on YOUR LOCAL machine (not the server):
scp /path/to/dishnet-hybrid-v4.11.xx.zip root@YOUR_SERVER_IP:/tmp/

# Back on the server — replace plugin files
cd /data/ucrm/plugins/dishnet-hybrid-telecom/
unzip -o /tmp/dishnet-hybrid-v4.11.xx.zip
echo "Plugin files replaced"
```

**Plugin data is safe.** The data directory is separate from the plugin code:
- Plugin code: `/data/ucrm/plugins/dishnet-hybrid-telecom/` ← you're replacing this
- Plugin data: `/data/ucrm/data/plugins/dishnet-hybrid-telecom/data/` ← NEVER touched

### Step 3: If running in Docker (check first)

```bash
# Check if UCRM runs in Docker
docker ps | grep ucrm

# If yes, run commands inside the container:
docker exec -it ucrm_web_1 bash
# Then run the rm/unzip commands from Step 1 or Step 2 above
```

### Step 4: Test the database health

```bash
# SQLite health check
sqlite3 /data/ucrm/data/plugins/dishnet-hybrid-telecom/data/plugin.sqlite3 \
  "PRAGMA integrity_check; SELECT COUNT(*) FROM sqlite_master;"

# Expected output: "ok" then a number (table count)
```

---

## Option 4 — Restore from Google Drive backup

Only if the database is corrupted (integrity_check shows errors).

1. Go to Google Drive → **DishNet Backups** folder
2. Download the most recent ZIP backup
3. Extract it — find `data/plugin.sqlite3`
4. On server:
   ```bash
   cp /data/ucrm/data/plugins/dishnet-hybrid-telecom/data/plugin.sqlite3 \
      /data/ucrm/data/plugins/dishnet-hybrid-telecom/data/plugin.sqlite3.broken
   # Then copy the backup file to the data directory
   ```

**Never do a full server/DigitalOcean restore just for a plugin issue.**
The plugin data lives in one file. Restore that file, not the whole server.

---

## What each error means

| Error | Cause | Fix |
|-------|-------|-----|
| `SqliteStore.php:105` | Stale WAL file | Option 1 (repair URL) or Option 3 Step 1 |
| `System Error` on all pages | Plugin crash | Option 2 (upload via UCRM admin) |
| `Call to undefined function` | PHP version issue | Contact support |
| Blank white page | Fatal PHP error | Option 3 Step 2 (replace files) |
| Data appears missing | Database issue | Option 4 (restore from backup) |

---

## Prevent crashes: keep this schedule

| Action | When |
|--------|------|
| Check Google Drive backups exist | Weekly |
| Update plugin | Only when no staff are actively using it |
| Run repair URL after each update | As a precaution |

---

*Last updated: v4.11.20 — DishNet Africa / DishNet Hybrid Plugin*
