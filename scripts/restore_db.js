const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');

const DB = {
  host:     'yamanote.proxy.rlwy.net',
  user:     'root',
  password: 'ahFYtSstCghtlLjIzkwRJJaTHXJifVdY',
  database: 'railway',
  port:     58498,
};

async function main() {
  const sql = fs.readFileSync(path.join(__dirname, '..', 'database', 'crm_checklist (1).sql'), 'utf8');

  const conn = await mysql.createConnection(DB);
  console.log('Connected to production DB');

  await conn.query('SET FOREIGN_KEY_CHECKS = 0');

  // Count INSERTs per table
  const counts = {};
  const re = /INSERT INTO `(\w+)`/g;
  let m;
  while ((m = re.exec(sql)) !== null) {
    counts[m[1]] = (counts[m[1]] || 0) + 1;
  }
  console.log('INSERT statements found:', counts);

  // Clear all
  await conn.query('DELETE FROM batch_tags');
  await conn.query('DELETE FROM items');
  await conn.query('DELETE FROM tags');
  await conn.query('DELETE FROM batches');
  await conn.query('DELETE FROM users');
  await conn.query('DELETE FROM visitors');
  console.log('Cleared existing data');

  // Extract all INSERT statements for a table
  function extractInserts(tableName) {
    const pattern = new RegExp(`INSERT INTO \`${tableName}\` .*? VALUES\\s*((?:\\([^;]+?\\),?\\s*)+);`, 'gs');
    const allValues = [];
    let match;
    while ((match = pattern.exec(sql)) !== null) {
      const block = match[1].replace(/\);?$/,'');
      const rows = block.split(/\),\s*\(/).map(v => {
        v = v.replace(/^\(|\)$/g, '').trim();
        return v;
      });
      allValues.push(...rows);
    }
    return allValues;
  }

  // ── batches: remove tag_id column ──────────────────────
  const batchRows = extractInserts('batches');
  for (let i = 0; i < batchRows.length; i += 200) {
    const chunk = batchRows.slice(i, i + 200).map(v => {
      const cols = parseRow(v);
      cols.pop(); // remove tag_id
      return `(${cols.join(',')})`;
    });
    await conn.query(`INSERT INTO batches (id, name, workflow_name, assigned_to, organization, casino_name, campaign_dates, created_at, updated_at) VALUES ${chunk.join(',')}`);
  }
  console.log(`Restored ${batchRows.length} batches`);

  // ── tags ────────────────────────────────────────────────
  const tagRows = extractInserts('tags');
  for (let i = 0; i < tagRows.length; i += 200) {
    const chunk = tagRows.slice(i, i + 200).map(v => `(${v})`);
    await conn.query(`INSERT INTO tags (id, name, color, created_at) VALUES ${chunk.join(',')}`);
  }
  console.log(`Restored ${tagRows.length} tags`);

  // ── batch_tags: remove id column ────────────────────────
  const btRows = extractInserts('batch_tags');
  for (let i = 0; i < btRows.length; i += 200) {
    const chunk = btRows.slice(i, i + 200).map(v => {
      const cols = parseRow(v);
      cols.shift(); // remove id
      return `(${cols.join(',')})`;
    });
    await conn.query(`INSERT INTO batch_tags (batch_id, tag_id, created_at) VALUES ${chunk.join(',')}`);
  }
  console.log(`Restored ${btRows.length} batch_tags`);

  // ── items ───────────────────────────────────────────────
  const itemRows = extractInserts('items');
  for (let i = 0; i < itemRows.length; i += 200) {
    const chunk = itemRows.slice(i, i + 200).map(v => `(${v})`);
    await conn.query(`INSERT INTO items (id, batch_id, name, label, item_date, item_time, checked, time_ok, crm_ok, sort_order, created_at, updated_at) VALUES ${chunk.join(',')}`);
  }
  console.log(`Restored ${itemRows.length} items`);

  // ── users ───────────────────────────────────────────────
  const userRows = extractInserts('users');
  for (let i = 0; i < userRows.length; i += 200) {
    const chunk = userRows.slice(i, i + 200).map(v => `(${v})`);
    await conn.query(`INSERT IGNORE INTO users (id, email, name, password_hash, is_active, created_at, last_login, picture, auth_provider) VALUES ${chunk.join(',')}`);
  }
  console.log(`Restored ${userRows.length} users`);

  // ── visitors: same schema! ─────────────────────────────
  const visRows = extractInserts('visitors');
  for (let i = 0; i < visRows.length; i += 200) {
    const chunk = visRows.slice(i, i + 200).map(v => `(${v})`);
    await conn.query(`INSERT INTO visitors (id, name, ip_address, user_agent, page_visited, visited_at) VALUES ${chunk.join(',')}`);
  }
  console.log(`Restored ${visRows.length} visitors`);

  // Re-seed special auth users
  const hash = await bcryptHash('247ga2024');
  await conn.query(`INSERT IGNORE INTO users (email, name, password_hash) VALUES ('paul.valencia@247ga.co', 'Paul Valencia', '${hash}')`);

  await conn.query('SET FOREIGN_KEY_CHECKS = 1');
  await conn.end();
  console.log('Restore complete!');
}

// Quick bcrypt hash via mysql2 (use MySQL's built-in password if needed)
async function bcryptHash(plain) {
  try {
    const bcrypt = require('bcryptjs');
    return bcrypt.hashSync(plain, 10);
  } catch (_) {
    return `$2b$10$placeholder`;
  }
}

function parseRow(str) {
  const parts = [];
  let current = '';
  let inQuote = false;
  let quoteChar = '';
  for (let i = 0; i < str.length; i++) {
    const c = str[i];
    if (inQuote) {
      current += c;
      if (c === quoteChar) {
        if (i + 1 < str.length && str[i + 1] === quoteChar) {
          current += str[++i]; // escaped quote
        } else {
          inQuote = false;
        }
      }
    } else if (c === "'" || c === '"') {
      inQuote = true;
      quoteChar = c;
      current += c;
    } else if (c === ',' && !inQuote) {
      parts.push(current.trim());
      current = '';
    } else {
      current += c;
    }
  }
  if (current.trim()) parts.push(current.trim());
  return parts;
}

main().catch(err => {
  console.error('Restore failed:', err.message);
  process.exit(1);
});
