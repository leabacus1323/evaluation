<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
ensure_google_integration_schema();
$eventId=(int)($_GET['event_id']??0);
$event=get_event_by_id($eventId);
if(!$event){http_response_code(404);exit('Event not found.');}
$source=(string)($_GET['source']??'local');
$localUrl=event_url($event);
$googleUrl=(string)($event['google_form_url']??'');
if($source==='google' && $googleUrl!==''){$url=$googleUrl;$sourceLabel='Google Form';}else{$url=$localUrl;$source='local';$sourceLabel='Website Evaluation';}
$pageTitle='Evaluation QR Code';$adminArea=true;require __DIR__.'/../includes/header.php';
?>
<div class="section-title"><div><h1>Evaluation QR Code</h1><p><?= h($event['title']) ?> · <?= h($event['code']) ?></p></div><button class="no-print" onclick="window.print()">Print QR Page</button></div>
<?php if($googleUrl!==''): ?><div class="actions no-print" style="margin-bottom:14px"><a class="btn <?= $source==='local'?'':'ghost' ?>" href="qr.php?event_id=<?= $eventId ?>&source=local">Website Evaluation</a><a class="btn <?= $source==='google'?'':'ghost' ?>" href="qr.php?event_id=<?= $eventId ?>&source=google">Google Form</a></div><?php endif; ?>
<div class="grid grid-2">
<div class="card card-pad"><div class="qr-box"><div id="qrcode"></div></div></div>
<div class="card card-pad"><div class="eyebrow"><?= h($sourceLabel) ?> Link</div><h2><?= h($event['title']) ?></h2><p class="muted">Participants can scan the QR code or open the link below.</p><div class="field"><label>Public URL</label><input id="evalUrl" value="<?= h($url) ?>" readonly></div><div class="actions no-print" style="margin-top:12px"><button onclick="navigator.clipboard.writeText(document.getElementById('evalUrl').value);this.textContent='Copied';return false">Copy Link</button><a class="btn ghost" target="_blank" rel="noopener" href="<?= h($url) ?>">Open <?= h($sourceLabel) ?></a></div><p style="margin-top:16px"><span class="status <?= h($event['status']) ?>"><?= h(ucfirst($event['status'])) ?></span></p><?php if($source==='google'): ?><p class="muted">Google Form responses are stored in the linked Google Sheets workbook in Google Drive.</p><?php else: ?><p class="muted">Website responses are stored in this system's MySQL database.</p><?php endif; ?></div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>new QRCode(document.getElementById('qrcode'),{text:<?= json_encode($url) ?>,width:240,height:240,colorDark:'#111827',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.H});</script>
<?php require __DIR__.'/../includes/footer.php'; ?>
