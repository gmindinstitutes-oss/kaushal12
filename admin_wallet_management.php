<?php
// admin_wallet_management.php
// Admin: Branch Wallet & Discount Management (logs TOP_UP with prev/new balances)

ob_start();
session_start();

if (!isset($_SESSION['admin'])) {
    header('Location: ../lagain.php');
    exit;
}

// PDO
$host = "localhost";
$db_name = "gmindin_kksingh";
$user = "gmindin_kksingh";
$pass = "Kk@9305640290";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$message = '';

// POST: update + log
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $branch_id = trim($_POST['branch_id'] ?? '');
    $new_wallet_balance = (float)($_POST['new_wallet_balance'] ?? 0);
    $new_discount_amount = (float)($_POST['new_discount_amount'] ?? 0);

    if ($branch_id === '') {
        $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg'>Branch ID missing.</div>";
    } else {
        try {
            $conn->beginTransaction();

            $stmtInfo = $conn->prepare("SELECT srno, wallet_balance FROM branch WHERE branch_id = :bid LIMIT 1");
            $stmtInfo->execute([':bid'=>$branch_id]);
            $info = $stmtInfo->fetch();
            if (!$info) { throw new Exception('Branch not found.'); }

            $branchSrno = (int)$info['srno'];
            $old_wallet_balance = (float)$info['wallet_balance'];
            $delta = $new_wallet_balance - $old_wallet_balance;

            $stmtUpd = $conn->prepare("UPDATE branch SET wallet_balance=:wb, discount_amount=:da WHERE branch_id=:bid");
            $stmtUpd->execute([':wb'=>$new_wallet_balance, ':da'=>$new_discount_amount, ':bid'=>$branch_id]);

            if ($delta > 0 && $branchSrno > 0) {
                $desc = sprintf(
                    'Admin top-up by %s | prev: %.2f | new: %.2f',
                    ($_SESSION['admin'] ?? 'admin'),
                    $old_wallet_balance,
                    $new_wallet_balance
                );
                $stmtLog = $conn->prepare("
                    INSERT INTO wallet_transactions (branch_id, transaction_type, amount, description)
                    VALUES (:srno, 'TOP_UP', :amt, :desc)
                ");
                $stmtLog->execute([':srno'=>$branchSrno, ':amt'=>$delta, ':desc'=>$desc]);
            }

            $conn->commit();
            $message = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg'>
                          Wallet/Discount updated. ".($delta>0 ? "Top-up logged: ₹".number_format($delta,2) : "No increase")."
                        </div>";
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $message = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg'>".$e->getMessage()."</div>";
        }
    }
}

// READ filters (search by code or name)
$q_code = trim($_GET['q_code'] ?? '');
$q_name = trim($_GET['q_name'] ?? '');

$whereClauses = [];
$params = [];
if ($q_code !== '') {
    $whereClauses[] = 'branch_id LIKE :q_code';
    $params[':q_code'] = '%'.$q_code.'%';
}
if ($q_name !== '') {
    $whereClauses[] = 'branch_name LIKE :q_name';
    $params[':q_name'] = '%'.$q_name.'%';
}
$whereSQL = '';
if (!empty($whereClauses)) {
    $whereSQL = ' WHERE ' . implode(' AND ', $whereClauses);
}

// Pagination
$records_per_page = 20;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) { $current_page = 1; }
$offset = ($current_page - 1) * $records_per_page;

// Total count with filters
$stmtCount = $conn->prepare("SELECT COUNT(*) FROM branch".$whereSQL);
foreach ($params as $k=>$v) { $stmtCount->bindValue($k, $v, PDO::PARAM_STR); }
$stmtCount->execute();
$total_records = (int)$stmtCount->fetchColumn();
$total_pages = (int)ceil(($total_records ?: 0) / $records_per_page);
if ($total_pages > 0 && $current_page > $total_pages) { $current_page = $total_pages; $offset = ($current_page - 1) * $records_per_page; }

// Data with filters + pagination
$sql = "SELECT branch_id, branch_name, wallet_balance, discount_amount FROM branch".$whereSQL." ORDER BY branch_id LIMIT :lim OFFSET :off";
$stmt = $conn->prepare($sql);
foreach ($params as $k=>$v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
$stmt->bindValue(':lim', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$branches = $stmt->fetchAll();

// Helper to build pagination URLs preserving search
function build_page_url(int $page): string {
    $query = $_GET;
    $query['page'] = $page;
    return '?' . http_build_query($query);
}

ob_end_flush();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin Wallet Management</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body{font-family:Inter,system-ui,Arial,sans-serif;background:#f4f7f9}
    .modal{transition:opacity .3s,visibility .3s;opacity:0;visibility:hidden}
    .modal.open{opacity:1;visibility:visible}
    .modal-overlay{background:rgba(0,0,0,.5)}
  </style>
</head>
<body class="p-8 md:p-12">

  <a href="dash.php" class="inline-flex items-center mb-6 px-4 py-2 text-sm font-medium text-gray-700 bg-white rounded-lg shadow-sm hover:bg-gray-50 border border-gray-300">
    <svg class="w-4 h-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7 7-7m-7 7h18"/></svg>
    Back to Dashboard
  </a>

  <div class="mb-8">
    <h1 class="text-3xl md:text-4xl font-bold text-gray-800">Branch Wallet & Discount Management</h1>
    <p class="mt-2 text-gray-600">Top-ups are auto-logged with previous and new balances.</p>
  </div>

  <div class="mb-6"><?php echo $message; ?></div>

  <!-- Search Bar -->
  <form method="GET" class="mb-6 bg-white p-4 md:p-6 rounded-xl shadow border border-gray-200">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Branch Code</label>
        <input type="text" name="q_code" value="<?php echo htmlspecialchars($q_code); ?>" placeholder="e.g. BR001" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Branch Name</label>
        <input type="text" name="q_name" value="<?php echo htmlspecialchars($q_name); ?>" placeholder="e.g. Central" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
      </div>
      <div class="flex items-end gap-2">
        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow">Search</button>
        <a href="?" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-800 font-medium rounded-lg border border-gray-300">Reset</a>
      </div>
    </div>
  </form>

  <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-gray-200">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-100">
          <tr>
            <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sr No.</th>
            <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch ID</th>
            <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Branch Name</th>
            <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Wallet Balance</th>
            <th class="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Discount Amount</th>
            <th class="py-3 px-6 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <?php if ($branches): $rowIndex = 0; foreach($branches as $b): $serial = $offset + (++$rowIndex); ?>
            <tr class="hover:bg-gray-50">
              <td class="py-4 px-6 text-sm text-gray-700"><?php echo $serial; ?></td>
              <td class="py-4 px-6 text-sm text-gray-700"><?php echo htmlspecialchars($b['branch_id']); ?></td>
              <td class="py-4 px-6 text-sm text-gray-700"><?php echo htmlspecialchars($b['branch_name']); ?></td>
              <td class="py-4 px-6 text-sm text-green-600 font-semibold">₹<?php echo number_format((float)$b['wallet_balance'],2); ?></td>
              <td class="py-4 px-6 text-sm text-gray-700">₹<?php echo number_format((float)$b['discount_amount'],2); ?></td>
              <td class="py-4 px-6 text-center">
                <button class="manage-btn bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg shadow"
                        data-branch-id="<?php echo htmlspecialchars($b['branch_id']); ?>"
                        data-branch-name="<?php echo htmlspecialchars($b['branch_name']); ?>"
                        data-wallet-balance="<?php echo htmlspecialchars($b['wallet_balance']); ?>"
                        data-discount-amount="<?php echo htmlspecialchars($b['discount_amount']); ?>">
                  Manage
                </button>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="py-6 text-center text-gray-500">No branches found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($total_pages > 1): ?>
    <div class="flex justify-center items-center gap-2 mt-8">
      <?php if ($current_page > 1): ?>
        <a href="<?php echo build_page_url($current_page-1); ?>" class="px-4 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-100">Previous</a>
      <?php endif; ?>
      <?php for($i=1;$i<=$total_pages;$i++): ?>
        <a href="<?php echo build_page_url($i); ?>" class="px-4 py-2 text-sm rounded-lg <?php echo $i===$current_page?'bg-blue-600 text-white':'bg-white border border-gray-300 hover:bg-gray-100'; ?>"><?php echo $i; ?></a>
      <?php endfor; ?>
      <?php if ($current_page < $total_pages): ?>
        <a href="<?php echo build_page_url($current_page+1); ?>" class="px-4 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-100">Next</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Modal -->
  <div id="manageModal" class="modal fixed inset-0 flex items-center justify-center p-4 z-50">
    <div class="modal-overlay absolute inset-0"></div>
    <div class="modal-content bg-white rounded-2xl shadow-2xl p-8 max-w-lg w-full relative z-10">
      <div class="flex justify-between items-center mb-6">
        <h2 id="modalTitle" class="text-2xl font-bold text-gray-800">Manage Wallet & Discount</h2>
        <button id="closeModalBtn" class="text-gray-400 hover:text-gray-600 text-xl font-bold">&times;</button>
      </div>

      <form method="POST">
        <input type="hidden" id="modalBranchId" name="branch_id">

        <div class="mb-4">
          <label class="block text-gray-700 font-medium mb-2">New Wallet Balance</label>
          <input type="number" step="0.01" id="newWalletBalance" name="new_wallet_balance" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" required>
          <p class="text-xs text-gray-500 mt-1">Top‑up history logs the increased amount only (difference).</p>
        </div>

        <div class="mb-6">
          <label class="block text-gray-700 font-medium mb-2">New Discount Amount</label>
          <input type="number" step="0.01" id="newDiscountAmount" name="new_discount_amount" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" required>
        </div>

        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 rounded-lg shadow">Save Changes</button>
      </form>
    </div>
  </div>

  <script>
    const modal = document.getElementById('manageModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const manageButtons = document.querySelectorAll('.manage-btn');
    const modalTitle = document.getElementById('modalTitle');

    manageButtons.forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const id = btn.getAttribute('data-branch-id');
        const name = btn.getAttribute('data-branch-name');
        const wb = btn.getAttribute('data-wallet-balance');
        const da = btn.getAttribute('data-discount-amount');
        modalTitle.textContent = `Manage for: ${name}`;
        document.getElementById('modalBranchId').value = id;
        document.getElementById('newWalletBalance').value = wb;
        document.getElementById('newDiscountAmount').value = da;
        modal.classList.add('open');
      });
    });
    function closeM(){ modal.classList.remove('open'); }
    closeModalBtn.addEventListener('click', closeM);
    modal.addEventListener('click', e=>{ if (e.target.classList.contains('modal-overlay')) closeM(); });
  </script>
</body>
</html>