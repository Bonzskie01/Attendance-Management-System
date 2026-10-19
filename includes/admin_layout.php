<?php
function admin_page_start($title, $active = 'dashboard') {
    $nested = preg_match('#/admin/[^/]+/#', $_SERVER['PHP_SELF'] ?? '');
    $asset = $nested ? '../../' : '../';
    $links = [
        'dashboard' => ['dashboard.php', 'fa-chart-pie', 'Overview'],
        'requests' => ['requests/index.php', 'fa-inbox', 'Information Requests'],
        'events' => ['events/index.php', 'fa-calendar-days', 'Events'],
        'attendees' => ['attendees/index.php', 'fa-graduation-cap', 'Students'],
        'attendance' => ['attendance/index.php', 'fa-user-check', 'Check-in Desk'],
        'photos' => ['evidence/index.php', 'fa-images', 'Photo Archive'],
        'payments' => ['payments/index.php', 'fa-receipt', 'Payment Ledger'],
        'fines' => ['fines/index.php', 'fa-gavel', 'Fines'],
        'officers' => ['officers/index.php', 'fa-user-tie', 'Officers'],
        'players' => ['players/index.php', 'fa-trophy', 'Players'],
        'reports' => ['reports/index.php', 'fa-chart-column', 'Reports'],
        'audit' => ['audit/index.php', 'fa-clock-rotate-left', 'Audit Logs'],
        'data' => ['data/index.php', 'fa-database', 'Data Management'],
    ]; ?>
    <?php if(($_SESSION['admin_role']??'admin')==='admin'){$links['staff']=['staff/index.php','fa-user-shield','Marshals'];}else{$links=['attendance'=>$links['attendance']];} ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> · <?=h(site_name())?></title>
<link rel="icon" href="<?=$asset.h(site_favicon())?>"><link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css"><link rel="stylesheet" href="<?=$asset?>assets/css/feedback.css"><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>(function(){if(localStorage.getItem('vms-theme')==='dark')document.documentElement.setAttribute('data-theme','dark')})()</script></head>
<body><div class="admin-bg" style="background-image:url('<?=$asset.h(site_background())?>')"></div><div class="app"><aside class="sidebar" id="sidebar"><div class="brand"><img src="<?=$asset.h(site_logo())?>" alt=""><div><strong><?=h(site_name())?></strong><small>Admin Panel</small></div></div><nav class="nav">
<?php foreach($links as $key=>[$href,$icon,$label]):$url=$nested?'../'.$href:$href;?><a class="<?=$active===$key?'active':''?>" href="<?=h($url)?>"><i class="fa-solid <?=h($icon)?>"></i><?=h($label)?></a><?php endforeach;?>
<div class="nav-divider"></div><a class="<?=$active==='settings'?'active':''?>" href="<?=$nested?'../settings.php':'settings.php'?>"><i class="fa-solid fa-gear"></i> Settings</a><a class="<?=$active==='security'?'active':''?>" href="<?=$nested?'../profile.php':'profile.php'?>"><i class="fa-solid fa-shield-halved"></i> Account Security</a><a class="logout-link" href="#" onclick="confirmLogout(event)"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
</nav><div class="sidebar-foot">© <?=date('Y')?> <?=h(site_name())?></div></aside><div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<main class="main"><header class="topbar"><div style="display:flex;align-items:center;gap:12px"><button class="topbar-btn mobile-menu" type="button" onclick="openSidebar()"><i class="fa-solid fa-bars"></i></button><h1><?=h($title)?></h1></div><div class="topbar-right"><div class="user"><span class="avatar"><i class="fa-solid fa-user-shield"></i></span><span>Welcome, <strong><?=h($_SESSION['admin_full_name']??$_SESSION['admin_username']??'Administrator')?></strong></span></div><button class="topbar-btn" type="button" onclick="toggleTheme()"><i class="fa-solid fa-moon" id="themeIcon"></i></button><a class="topbar-btn" href="#" onclick="confirmLogout(event)"><i class="fa-solid fa-right-from-bracket"></i></a></div></header><section class="content">
<?php }
function admin_page_end(){ $asset=preg_match('#/admin/[^/]+/#',$_SERVER['PHP_SELF']??'')?'../../':'../'; ?>
</section></main></div><script>
function amsSwalOptions(){const dark=document.documentElement.getAttribute('data-theme')==='dark';return{background:dark?'#0f1e4a':'#fff',color:dark?'#f1f5f9':'#1e293b',confirmButtonColor:'#1a237e',cancelButtonColor:'#64748b'}}
function toggleTheme(){const d=document.documentElement,n=d.getAttribute('data-theme')==='dark';if(n){d.removeAttribute('data-theme');localStorage.setItem('vms-theme','light')}else{d.setAttribute('data-theme','dark');localStorage.setItem('vms-theme','dark')}updateThemeIcon()}
function updateThemeIcon(){const i=document.getElementById('themeIcon');if(i)i.className=document.documentElement.getAttribute('data-theme')==='dark'?'fa-solid fa-sun':'fa-solid fa-moon'}
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sidebarOverlay').classList.add('show')}function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('show')}
function confirmLogout(e){e.preventDefault();Swal.fire({...amsSwalOptions(),title:'Sign out?',text:'You will need to sign in again to manage attendance.',icon:'question',showCancelButton:true,confirmButtonText:'Yes, sign out'}).then(r=>{if(r.isConfirmed)window.location.href='<?=$asset?>logout.php'})}
document.addEventListener('submit',function(e){const f=e.target.closest('form[data-confirm]');if(!f||f.dataset.confirmed==='1')return;e.preventDefault();const submitter=e.submitter;Swal.fire({...amsSwalOptions(),title:f.dataset.confirmTitle||'Are you sure?',text:f.dataset.confirm||'Please confirm this action.',icon:'warning',showCancelButton:true,confirmButtonText:f.dataset.confirmButton||'Yes, continue'}).then(r=>{if(r.isConfirmed){f.dataset.confirmed='1';f.requestSubmit(submitter)}})},true);
document.querySelectorAll('.ams-flash[data-swal-title]').forEach(el=>{Swal.fire({...amsSwalOptions(),toast:true,position:'top-end',showConfirmButton:false,timer:2800,icon:el.dataset.swalIcon||'success',title:el.dataset.swalTitle});el.remove()});document.addEventListener('keydown',e=>{if(e.key==='Escape')closeSidebar()});updateThemeIcon();
</script></body></html><?php }
