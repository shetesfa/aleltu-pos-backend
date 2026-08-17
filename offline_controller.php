<?php
/**
 * ALELTU — Offline permission — product-level only (simplified)
 * Controls: per-product "allow offline sale" + optional offline quantity cap.
 * Uses existing offline_rules table with rule_scope='PRODUCT'.
 */
require_once 'config.php';
$uid  = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? '';
if (!$uid || !in_array($role, ['admin','super_admin','boss'])) { header("Location: index.php"); exit(); }

$branch_id   = (int)getCurrentBranchId($conn, $uid, $role);
$branch_name = getCurrentBranchName($conn, $branch_id);
$msg = ''; $err = '';

/* ── SAVE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_permissions') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        die('Invalid or expired request. Please refresh the page.');
    }
    $allowed_ids = array_map('intval', $_POST['allow'] ?? []); // product ids checked "allowed offline"
    $limits      = $_POST['limit'] ?? []; // product_id => qty (string), blank/0 = unlimited

    $products_res = mysqli_query($conn, "SELECT id FROM products WHERE branch_id=$branch_id AND is_active=1");
    $all_ids = [];
    while ($row = mysqli_fetch_assoc($products_res)) { $all_ids[] = (int)$row['id']; }

    foreach ($all_ids as $pid) {
        $allow_offline = in_array($pid, $allowed_ids, true) ? 1 : 0;
        $max_qty = isset($limits[$pid]) && $limits[$pid] !== '' ? (float)$limits[$pid] : 0;

        $chk = mysqli_query($conn, "SELECT id FROM offline_rules WHERE branch_id=$branch_id AND rule_scope='PRODUCT' AND target_id=$pid LIMIT 1");
        if ($existing = mysqli_fetch_assoc($chk)) {
            $stmt = mysqli_prepare($conn, "UPDATE offline_rules SET allow_offline=?, max_offline_qty=?, is_active=1 WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'idi', $allow_offline, $max_qty, $existing['id']);
            mysqli_stmt_execute($stmt);
        } else {
            $rule_name = "product_$pid";
            $stmt = mysqli_prepare($conn,
                "INSERT INTO offline_rules (rule_name, rule_scope, target_id, branch_id, max_offline_qty, allow_offline, priority, created_by)
                 VALUES (?, 'PRODUCT', ?, ?, ?, ?, 100, ?)");
            mysqli_stmt_bind_param($stmt, 'siidii', $rule_name, $pid, $branch_id, $max_qty, $allow_offline, $uid);
            mysqli_stmt_execute($stmt);
        }
    }
    $msg = 'ተቀምጧል! የኦፍላይን ፍቃዶች ተዘምነዋል።';
}

/* ── DATA ── */
$products_res = mysqli_query($conn, "SELECT id, name, unit_price FROM products WHERE branch_id=$branch_id AND is_active=1 ORDER BY name ASC");
$products = mysqli_fetch_all($products_res ?? [], MYSQLI_ASSOC);

$rules_res = mysqli_query($conn, "SELECT target_id, allow_offline, max_offline_qty FROM offline_rules WHERE branch_id=$branch_id AND rule_scope='PRODUCT'");
$rule_map = [];
while ($r = mysqli_fetch_assoc($rules_res)) { $rule_map[(int)$r['target_id']] = $r; }

// default: if no rule exists yet for a product, treat as ALLOWED (offline works out of the box)
$total = count($products);
$allowed_count = 0;
foreach ($products as $p) {
    $rule = $rule_map[$p['id']] ?? null;
    if ($rule === null || (int)$rule['allow_offline'] === 1) $allowed_count++;
}
$blocked_count = $total - $allowed_count;
?>
<!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>የኦፍላይን ፍቃድ — Aleltu</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0f172a; --card:#1e293b; --card2:#152032; --border:#2d3b52;
  --text:#f1f5f9; --muted:#94a3b8; --accent:#6366f1;
  --success:#10b981; --success-bg:rgba(16,185,129,.12);
  --danger:#ef4444; --danger-bg:rgba(239,68,68,.12);
  --warning:#f59e0b;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{
  font-family:'Noto Sans Ethiopic','Inter',sans-serif;
  background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:6rem;
}
.hdr{
  background:linear-gradient(135deg,#1e293b,#0f172a);
  border-bottom:1px solid var(--border);
  padding:1.1rem 1.5rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
  position:sticky; top:0; z-index:20;
}
.hdr h1{font-size:1.15rem; font-weight:800;}
.hdr .sub{font-size:.78rem; color:var(--muted); font-weight:500;}
.back{
  background:rgba(99,102,241,.15); color:#a5b4fc; border:1px solid rgba(99,102,241,.3);
  border-radius:9px; padding:.5rem 1rem; font-size:.85rem; text-decoration:none; font-weight:600;
}
.back:hover{background:rgba(99,102,241,.3);}

.wrap{max-width:900px; margin:0 auto; padding:1.5rem;}

.alert{padding:.9rem 1.1rem; border-radius:12px; margin-bottom:1.25rem; font-size:.9rem; font-weight:600;}
.alert.ok{background:var(--success-bg); border:1px solid rgba(16,185,129,.3); color:#6ee7b7;}

.explain{
  background:var(--card); border:1px solid var(--border); border-radius:16px;
  padding:1.25rem 1.4rem; margin-bottom:1.25rem; font-size:.92rem; line-height:1.9; color:#dbeafe;
}
.explain b{color:#fff;}

.stats{display:grid; grid-template-columns:1fr 1fr; gap:.9rem; margin-bottom:1.25rem;}
.stat{
  background:var(--card2); border:1px solid var(--border); border-radius:14px;
  padding:1rem 1.2rem; text-align:center;
}
.stat .n{font-size:1.7rem; font-weight:800;}
.stat.allow .n{color:var(--success);}
.stat.block .n{color:var(--danger);}
.stat .l{font-size:.78rem; color:var(--muted); margin-top:.2rem; font-weight:600;}

.toolbar{display:flex; gap:.6rem; margin-bottom:1rem; flex-wrap:wrap;}
.tbtn{
  background:var(--card2); border:1px solid var(--border); color:var(--text);
  padding:.55rem 1rem; border-radius:10px; font-size:.82rem; font-weight:600; cursor:pointer;
}
.tbtn:hover{border-color:var(--accent); color:#a5b4fc;}

.search{
  width:100%; background:var(--card2); border:1px solid var(--border); border-radius:12px;
  padding:.75rem 1rem; color:var(--text); font-size:.95rem; margin-bottom:1.1rem;
}
.search:focus{outline:none; border-color:var(--accent);}

.plist{display:flex; flex-direction:column; gap:.7rem;}
.prow{
  background:var(--card); border:1.5px solid var(--border); border-radius:14px;
  padding:1rem 1.1rem; display:flex; align-items:center; gap:1rem; transition:.15s;
}
.prow.is-allowed{border-color:rgba(16,185,129,.35);}
.prow.is-blocked{border-color:rgba(239,68,68,.35); background:rgba(239,68,68,.05);}

.pinfo{flex:1; min-width:0;}
.pname{font-size:1rem; font-weight:700;}
.pprice{font-size:.78rem; color:var(--muted); margin-top:.15rem;}

.plimit{width:110px;}
.plimit label{display:block; font-size:.68rem; color:var(--muted); margin-bottom:.25rem; font-weight:600;}
.plimit input{
  width:100%; background:var(--card2); border:1px solid var(--border); border-radius:8px;
  padding:.45rem .6rem; color:var(--text); font-size:.85rem;
}
.plimit input:disabled{opacity:.35;}

/* toggle switch */
.switch{position:relative; display:inline-block; width:52px; height:30px; flex-shrink:0;}
.switch input{opacity:0; width:0; height:0;}
.slider{
  position:absolute; cursor:pointer; inset:0; background:#475569; border-radius:30px; transition:.2s;
}
.slider:before{
  position:absolute; content:""; height:24px; width:24px; left:3px; bottom:3px;
  background:white; border-radius:50%; transition:.2s;
}
.switch input:checked + .slider{background:var(--success);}
.switch input:checked + .slider:before{transform:translateX(22px);}

.savebar{
  position:fixed; bottom:0; left:0; right:0; background:linear-gradient(0deg,#0f172a 70%,transparent);
  padding:1.5rem 1.5rem 1.25rem; display:flex; justify-content:center; z-index:30;
}
.savebtn{
  background:var(--accent); color:#fff; border:none; border-radius:14px;
  padding:1rem 2.5rem; font-size:1rem; font-weight:800; cursor:pointer;
  box-shadow:0 8px 24px rgba(99,102,241,.4); max-width:500px; width:100%;
}
.savebtn:hover{background:#4f46e5;}

.empty{text-align:center; padding:3rem 1rem; color:var(--muted);}
</style>
</head>
<body>

<div class="hdr">
  <a href="admin_dashboard.php" class="back">← ዳሽቦርድ</a>
  <div>
    <h1>📦 የኦፍላይን ሽያጭ ፍቃድ</h1>
    <div class="sub"><?=htmlspecialchars($branch_name)?></div>
  </div>
</div>

<div class="wrap">

<?php if($msg): ?><div class="alert ok">✅ <?=htmlspecialchars($msg)?></div><?php endif; ?>

<div class="explain">
  ኢንተርኔት ጠፍቶ እያለ ሻጭ ማንኛውንም ምርት መሸጥ ይችላል። ከዚህ በታች ካልፈለጉ ብቻ ለተወሰኑ ምርቶች
  <b>“ያለ ኢንተርኔት አትሽጥ”</b> ብለው ማጥፋት ይችላሉ (ለምሳሌ ዋጋው ተደጋግሞ የሚቀየር ምርት ከሆነ)።
  በተጨማሪ ለምርት <b>የከፍተኛ መጠን ገደብ</b> ማስቀመጥ ይችላሉ (ባዶ ወይም 0 = ገደብ የለም)።
</div>

<div class="stats">
  <div class="stat allow"><div class="n"><?=$allowed_count?></div><div class="l">✅ ያለ ኢንተርኔት ሊሸጡ የሚችሉ</div></div>
  <div class="stat block"><div class="n"><?=$blocked_count?></div><div class="l">⛔ ኢንተርኔት ያስፈልጋቸዋል</div></div>
</div>

<?php if ($products): ?>
<input type="text" class="search" id="searchBox" placeholder="🔍 ምርት ፈልግ..." oninput="filterProducts()">

<div class="toolbar">
  <button type="button" class="tbtn" onclick="setAll(true)">✅ ሁሉንም ፍቀድ</button>
  <button type="button" class="tbtn" onclick="setAll(false)">⛔ ሁሉንም ከልክል</button>
</div>

<form method="POST" id="permForm">
<input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
<input type="hidden" name="action" value="save_permissions">

<div class="plist" id="plist">
  <?php foreach ($products as $p):
    $pid = (int)$p['id'];
    $rule = $rule_map[$pid] ?? null;
    $is_allowed = $rule === null ? true : ((int)$rule['allow_offline'] === 1);
    $limit_val = $rule && (float)$rule['max_offline_qty'] > 0 ? $rule['max_offline_qty'] : '';
  ?>
  <div class="prow <?=$is_allowed?'is-allowed':'is-blocked'?>" data-name="<?=htmlspecialchars(mb_strtolower($p['name']))?>">
    <label class="switch">
      <input type="checkbox" name="allow[]" value="<?=$pid?>" <?=$is_allowed?'checked':''?> onchange="rowToggled(this)">
      <span class="slider"></span>
    </label>
    <div class="pinfo">
      <div class="pname"><?=htmlspecialchars($p['name'])?></div>
      <div class="pprice"><?=number_format($p['unit_price'],2)?> ብር</div>
    </div>
    <div class="plimit">
      <label>ከፍተኛ መጠን</label>
      <input type="number" step="0.01" min="0" name="limit[<?=$pid?>]" value="<?=$limit_val?>" placeholder="ገደብ የለም" <?=$is_allowed?'':'disabled'?>>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="savebar">
  <button type="submit" class="savebtn">💾 አስቀምጥ</button>
</div>
</form>

<?php else: ?>
  <div class="empty">ምንም ምርት አልተገኘም።</div>
<?php endif; ?>

</div><!-- /.wrap -->

<script>
function rowToggled(cb){
  const row = cb.closest('.prow');
  const limitInput = row.querySelector('input[type=number]');
  if(cb.checked){
    row.classList.remove('is-blocked'); row.classList.add('is-allowed');
    limitInput.disabled = false;
  } else {
    row.classList.remove('is-allowed'); row.classList.add('is-blocked');
    limitInput.disabled = true;
  }
}
function setAll(allow){
  document.querySelectorAll('.prow input[type=checkbox]').forEach(cb=>{
    cb.checked = allow;
    rowToggled(cb);
  });
}
function filterProducts(){
  const q = document.getElementById('searchBox').value.trim().toLowerCase();
  document.querySelectorAll('.prow').forEach(row=>{
    row.style.display = row.dataset.name.includes(q) ? 'flex' : 'none';
  });
}
</script>
</body>
</html>