const mysql = require('mysql2/promise');
(async () => {
  const conn = await mysql.createConnection({host:'yamanote.proxy.rlwy.net',user:'root',password:'ahFYtSstCghtlLjIzkwRJJaTHXJifVdY',database:'railway',port:58498});
  const [b] = await conn.query("SELECT id, name, assigned_to, created_at FROM batches WHERE assigned_to LIKE '%jb%' OR assigned_to LIKE '%delrosario%'");
  console.log('Batches with JB references:', JSON.stringify(b));
  const [a] = await conn.query('SELECT DISTINCT assigned_to FROM batches WHERE assigned_to IS NOT NULL AND assigned_to != ""');
  console.log('\nAll assigned_to values:', a.map(x => x.assigned_to));
  await conn.end();
})();
