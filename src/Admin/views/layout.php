<?php // src/Admin/views/layout.php
$theme = $theme ?? '';
$email = $email ?? '';
$tables = $tables ?? [];
$activeTable = $activeTable ?? null;
$inspect = $inspect ?? '';
$csrf = $csrf ?? '';
$back = $back ?? '/admin';
?>
<!doctype html>
<html lang="en"<?= $theme === '' ? '' : ' data-theme="' . $this->e($theme) . '"' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="data:,"><!-- empty data icon: without it browsers request /favicon.ico on every page and log a 404 -->
  <title><?= $this->e($title ?? 'Kip Admin') ?></title>
  <style>
:root{color-scheme:light;
  --kip-bg:#f8f7f3;--kip-panel:#fff;--kip-side:#f1efe8;
  --kip-line:#e4e2da;--kip-line-soft:#eeece6;
  --kip-text:#1c2320;--kip-body:#4b544e;--kip-faint:#5f6962;--kip-faintest:#a3a8a2;
  --kip-accent:#1f4f42;--kip-accent-hover:#2a6a58;
  --kip-btn:#1f4f42;--kip-btn-hover:#2a6a58;--kip-btn-active:#174036;
  --kip-accent-soft:#e3ece6;--kip-ring:#cfe3d9;
  --kip-danger:#9c2f1d;--kip-danger-btn:#9c2f1d;--kip-danger-btn-hover:#83261a;--kip-danger-btn-active:#6d2016;--kip-danger-soft:#f6e6e1;
  --kip-wash:#e7e5dc;--kip-head:#faf9f6;--kip-input-line:#cfccc2;
  --kip-warn-bg:#f7ecd4;--kip-warn-text:#7a4d00;
  --kip-chip-bg:#efede6;--kip-chip-text:#4b544e;
  --kip-btn-line:#d6d3c9;
  --kip-code-bg:#17332b;--kip-code-text:#dcebe3;--kip-code-type:#e8cf9a;
  --kip-serif:ui-serif,'Iowan Old Style',Charter,'Bitstream Charter',Georgia,serif;
  --kip-mono:ui-monospace,'SF Mono',Menlo,monospace}
:root[data-theme="dark"]{color-scheme:dark;
  --kip-bg:#141816;--kip-panel:#1b201d;--kip-side:#101311;
  --kip-line:#2a312d;--kip-line-soft:#232a26;
  --kip-text:#e6ebe7;--kip-body:#b4bdb7;--kip-faint:#939d96;--kip-faintest:#6a736d;
  --kip-accent:#8fc9b0;--kip-accent-hover:#a5d6c2;
  --kip-btn:#2f6b5a;--kip-btn-hover:#3a7d6a;--kip-btn-active:#285c4d;
  --kip-accent-soft:#1d3a31;--kip-ring:#2f6b5a;
  --kip-danger:#f08a74;--kip-danger-btn:#b23b26;--kip-danger-btn-hover:#c4452e;--kip-danger-btn-active:#9a3220;--kip-danger-soft:#3a211b;
  --kip-wash:#232a26;--kip-head:#1f2522;--kip-input-line:#3a433e;
  --kip-warn-bg:#3a2e14;--kip-warn-text:#e8c270;
  --kip-chip-bg:#242b27;--kip-chip-text:#b4bdb7;
  --kip-btn-line:#3a433e;
  --kip-code-bg:#0c1411}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){color-scheme:dark;
  --kip-bg:#141816;--kip-panel:#1b201d;--kip-side:#101311;
  --kip-line:#2a312d;--kip-line-soft:#232a26;
  --kip-text:#e6ebe7;--kip-body:#b4bdb7;--kip-faint:#939d96;--kip-faintest:#6a736d;
  --kip-accent:#8fc9b0;--kip-accent-hover:#a5d6c2;
  --kip-btn:#2f6b5a;--kip-btn-hover:#3a7d6a;--kip-btn-active:#285c4d;
  --kip-accent-soft:#1d3a31;--kip-ring:#2f6b5a;
  --kip-danger:#f08a74;--kip-danger-btn:#b23b26;--kip-danger-btn-hover:#c4452e;--kip-danger-btn-active:#9a3220;--kip-danger-soft:#3a211b;
  --kip-wash:#232a26;--kip-head:#1f2522;--kip-input-line:#3a433e;
  --kip-warn-bg:#3a2e14;--kip-warn-text:#e8c270;
  --kip-chip-bg:#242b27;--kip-chip-text:#b4bdb7;
  --kip-btn-line:#3a433e;
  --kip-code-bg:#0c1411}}
*{box-sizing:border-box}
html,body{margin:0}
body{background:var(--kip-bg);color:var(--kip-text);font:16px/1.6 system-ui,-apple-system,'Segoe UI',sans-serif;-webkit-font-smoothing:antialiased}
@media (min-width:800px){body{display:flex;min-height:100vh}}
a{color:var(--kip-accent);text-decoration:none}
a:hover{color:var(--kip-accent-hover)}
:focus-visible{outline:2px solid var(--kip-accent);outline-offset:2px}
code{font-family:var(--kip-mono);font-size:.92em}
.kip-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}

/* the sidebar is one <details>: disclosure on mobile, pinned open on desktop
   by @supports (engines without ::details-content keep the summary as a
   working toggle) */
.kip-topbar{display:flex;align-items:center;justify-content:space-between;height:56px;padding:0 12px 0 20px;background:var(--kip-side);border-bottom:1px solid var(--kip-line);position:sticky;top:0;z-index:20}
.kip-brand{display:flex;align-items:baseline;gap:8px;color:var(--kip-text)}
.kip-brand:hover{color:var(--kip-text)}
.kip-brand-name{font-family:var(--kip-serif);font-style:italic;font-size:26px;line-height:1}
.kip-brand-tag{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--kip-faint)}
.kip-menu-btn{display:flex;align-items:center;min-height:44px;padding:0 16px;border:1px solid var(--kip-btn-line);color:var(--kip-text);font-size:14px;font-weight:600;cursor:pointer;list-style:none}
.kip-menu-btn::-webkit-details-marker{display:none}
.kip-when-open{display:none}
.kip-sidebar[open] .kip-when-closed{display:none}
.kip-sidebar[open] .kip-when-open{display:inline}

.kip-sidebar{display:flex;flex-direction:column;gap:30px}
@media (min-width:800px){
  .kip-topbar{display:none}
  .kip-sidebar{display:block;position:sticky;top:0;height:100vh;width:248px;flex:none;background:var(--kip-side);border-right:1px solid var(--kip-line);padding:28px 16px;overflow:auto;gap:0}
}
@supports selector(.kip-sidebar::details-content){
  @media (min-width:800px){
    .kip-sidebar > summary{display:none}
    .kip-sidebar::details-content{content-visibility:visible;display:flex;flex-direction:column;gap:30px}
  }
}
@media (max-width:799px){
  .kip-sidebar > summary{position:fixed;top:6px;right:12px;z-index:30}
  .kip-sidebar[open]{position:fixed;inset:56px 0 0 0;background:var(--kip-side);padding:20px 16px;overflow:auto;z-index:30;flex-direction:column;gap:24px}
  .kip-sidebar[open]::details-content{display:contents}
}
.kip-nav{display:flex;flex-direction:column;gap:2px}
.kip-nav-title{margin:0 0 6px;padding:0 10px;font-size:11px;font-weight:400;letter-spacing:.1em;text-transform:uppercase;color:var(--kip-faint)}
.kip-nav-link{display:flex;justify-content:space-between;align-items:center;gap:8px;min-height:44px;padding:8px 10px;color:var(--kip-text);font-size:15px}
.kip-nav-link:hover{background:var(--kip-wash);color:var(--kip-text)}
.kip-nav-link[aria-current="page"]{background:var(--kip-panel);box-shadow:0 1px 2px rgba(28,35,32,.08);color:var(--kip-accent);font-weight:600}
.kip-nav-count{color:var(--kip-faint);font-variant-numeric:tabular-nums;font-size:13px}
.kip-nav-link[aria-current="page"] .kip-nav-count{color:var(--kip-accent);font-weight:500}
@media (max-width:799px){.kip-nav-link[aria-current="page"]{background:#0000;box-shadow:none;border-left:3px solid var(--kip-btn)}}
.kip-identity{margin-top:auto;display:flex;flex-direction:column;gap:10px;padding:14px 10px 0;border-top:1px solid var(--kip-line);font-size:13px;color:var(--kip-faint)}
.kip-identity .kip-nav-title{padding:0}
.kip-theme-buttons{display:flex;border:1px solid var(--kip-btn-line)}
.kip-theme-buttons button{flex:1;min-height:44px;padding:7px 0;border:0;border-left:1px solid var(--kip-btn-line);background:transparent;color:var(--kip-body);font:inherit;font-size:12.5px;font-weight:500;cursor:pointer}
.kip-theme-buttons button:first-child{border-left:0}
.kip-theme-buttons button:hover{color:var(--kip-text);background:var(--kip-wash)}
.kip-theme-buttons button[aria-pressed="true"]{background:var(--kip-text);color:var(--kip-side);font-weight:600}
.kip-signed-in{margin:6px 0 0}
.kip-signed-in strong{color:var(--kip-text);font-weight:600}
.kip-back{min-height:44px;display:flex;align-items:center}

.kip-main{flex:1;min-width:0;padding:48px 64px;display:flex;flex-direction:column;gap:36px}
@media (max-width:799px){.kip-main{padding:24px 20px 28px;gap:20px}}
.kip-pagehead{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;flex-wrap:wrap}
.kip-pagehead-intro{display:flex;flex-direction:column;gap:8px;min-width:0}
.kip-kicker{margin:0;font-size:13px;color:var(--kip-faint);font-family:var(--kip-mono)}
.kip-lede{margin:0;font-size:15px;color:var(--kip-body);max-width:560px}
h1{margin:0;font-family:var(--kip-serif);font-weight:400;font-size:44px;line-height:1.1;letter-spacing:-.01em}
@media (max-width:799px){h1{font-size:32px}}
.kip-crumb{display:flex;gap:8px;flex-wrap:wrap;font-size:13px;color:var(--kip-faint)}
.kip-crumb a{color:var(--kip-faint)}
.kip-crumb a:hover{color:var(--kip-accent)}
.kip-head-actions{display:flex;gap:10px;align-items:center}
.kip-btn-ghost{display:inline-flex;align-items:center;padding:10px 14px;color:var(--kip-accent);font-size:14px;font-weight:500}
.kip-btn-ghost:hover{background:var(--kip-accent-soft)}
.kip-btn-solid{display:inline-flex;align-items:center;padding:10px 18px;background:var(--kip-btn);color:#fff;font-size:14px;font-weight:500}
.kip-btn-solid:hover{background:var(--kip-btn-hover);color:#fff}
.kip-btn-solid:active{background:var(--kip-btn-active)}
.kip-handle{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;background:var(--kip-accent-soft);color:var(--kip-accent);font-size:13px;font-weight:600}
.kip-handle::before{content:'';width:7px;height:7px;border-radius:50%;background:var(--kip-btn)}

.kip-cards{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.kip-card{background:var(--kip-panel);border:1px solid var(--kip-line);padding:22px 24px;display:flex;flex-direction:column;gap:18px}
.kip-card-top{display:flex;justify-content:space-between;align-items:baseline;gap:12px;min-width:0}
.kip-card-name{font-family:var(--kip-mono);font-size:15px;font-weight:600;color:var(--kip-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kip-card-count{font-family:var(--kip-serif);font-size:30px;font-variant-numeric:tabular-nums}
.kip-card-actions{display:flex;gap:8px}
.kip-card-browse{flex:1;text-align:center;padding:9px 12px;background:var(--kip-side);color:var(--kip-text);font-size:14px;font-weight:500}
.kip-card-browse:hover{background:var(--kip-wash);color:var(--kip-text)}
.kip-card-new{padding:9px 14px;border:1px solid var(--kip-line);color:var(--kip-accent);font-size:14px;font-weight:500}
.kip-card-new:hover{background:var(--kip-accent-soft);border-color:var(--kip-ring);color:var(--kip-accent)}
@media (max-width:799px){
  .kip-cards{grid-template-columns:1fr;gap:0;background:var(--kip-panel);border:1px solid var(--kip-line)}
  .kip-card{border:0;border-top:1px solid var(--kip-line-soft);padding:0;flex-direction:row;align-items:stretch}
  .kip-card:first-child{border-top:0}
  .kip-card-top{flex:1;min-height:60px;padding:16px 18px;align-items:center}
  .kip-card-count{font-size:24px}
  .kip-card-browse{display:none}
  .kip-card-new{flex:none;width:60px;min-height:60px;display:flex;align-items:center;justify-content:center;border:0;border-left:1px solid var(--kip-line-soft);font-size:22px}
  .kip-card-new:hover{background:var(--kip-accent-soft)}
}

.kip-callout{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:20px 24px;background:var(--kip-accent-soft);color:var(--kip-accent);margin-top:auto}
.kip-callout p{margin:0;font-size:15px;line-height:1.5}
.kip-callout .kip-btn-solid{background:var(--kip-btn);flex:none}
@media (max-width:799px){.kip-callout{flex-direction:column;align-items:flex-start;padding:18px 20px}.kip-callout .kip-btn-solid{background:transparent;border:1px solid var(--kip-btn);color:var(--kip-accent);min-height:44px}}

.kip-tablewrap{background:var(--kip-panel);border:1px solid var(--kip-line);overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:14.5px}
thead tr{background:var(--kip-head)}
th{text-align:left;padding:12px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--kip-faint);border-bottom:1px solid var(--kip-line);white-space:nowrap}
td{padding:14px 12px;border-bottom:1px solid var(--kip-line-soft);max-width:16rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:top}
tbody tr:hover{background:var(--kip-head)}
tbody tr:last-child td{border-bottom:0}
.kip-mono{font-family:var(--kip-mono);font-size:13px;color:var(--kip-faint)}
.kip-dim{color:var(--kip-body)}
.kip-strong{font-weight:500}
.kip-right{text-align:right}
.kip-nodata{color:var(--kip-faintest)}
.kip-empty{margin:0;padding:32px 24px;color:var(--kip-faint);text-align:center}
.kip-rowactions{white-space:nowrap;text-align:right}
.kip-edit{padding:6px 10px;color:var(--kip-accent);font-weight:500}
.kip-edit:hover{background:var(--kip-accent-soft);color:var(--kip-accent)}
.kip-delete{padding:6px 10px;color:var(--kip-danger);font-weight:500}
.kip-delete:hover{background:var(--kip-danger-soft);color:var(--kip-danger)}
.kip-chip{display:inline-block;padding:3px 10px;background:var(--kip-accent-soft);color:var(--kip-accent);font-size:12.5px;font-weight:600}
.kip-chip-no{background:var(--kip-chip-bg);color:var(--kip-chip-text)}
.kip-chip-warn{background:var(--kip-warn-bg);color:var(--kip-warn-text)}
.kip-chip-err{background:var(--kip-danger-soft);color:var(--kip-danger)}
.kip-method{display:inline-block;width:52px;font-weight:600;color:var(--kip-text)}

.kip-pager{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;font-size:14px;color:var(--kip-faint)}
.kip-pager-group{display:flex;gap:8px}
.kip-pager-link{padding:9px 14px;border:1px solid var(--kip-btn-line);background:var(--kip-panel);color:var(--kip-text);font-weight:500}
.kip-pager-link:hover{border-color:var(--kip-accent);color:var(--kip-accent)}
.kip-pager-link[aria-disabled="true"]{border-color:var(--kip-line);color:var(--kip-faintest);pointer-events:none}
.kip-text-link{padding:9px 14px;color:var(--kip-accent);font-weight:500}
.kip-text-link:hover{background:var(--kip-accent-soft)}

.kip-form{background:var(--kip-panel);border:1px solid var(--kip-line);padding:32px;display:flex;flex-direction:column;gap:22px;max-width:44rem}
.kip-field{display:flex;flex-direction:column;gap:8px}
.kip-label{font-size:14px;font-weight:600}
.kip-required{font-weight:400;color:var(--kip-danger)}
.kip-help{font-size:13px;color:var(--kip-faint);line-height:1.5}
input[type=text],input[type=password],input[type=number],select,textarea{width:100%;padding:11px 14px;border:1px solid var(--kip-input-line);border-radius:0;background:var(--kip-panel);color:var(--kip-text);font:inherit;font-size:15px}
@media (max-width:799px){input[type=text],input[type=password],input[type=number],select,textarea{font-size:16px}}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--kip-accent);box-shadow:0 0 0 3px var(--kip-ring)}
textarea{min-height:8rem}
.kip-check{display:flex;gap:14px;align-items:flex-start;padding:16px 18px;border:1px solid var(--kip-line);background:var(--kip-head);cursor:pointer}
.kip-check input{width:20px;height:20px;margin:1px 0 0;accent-color:var(--kip-btn);flex:none}
.kip-check-body{display:flex;flex-direction:column;gap:4px}
.kip-formbar{display:flex;align-items:center;gap:12px;padding-top:8px;border-top:1px solid var(--kip-line-soft);margin-top:4px}
.kip-submit{padding:11px 22px;border:0;border-radius:0;background:var(--kip-btn);color:#fff;font:inherit;font-size:15px;font-weight:500;cursor:pointer}
.kip-submit:hover{background:var(--kip-btn-hover)}
.kip-submit:active{background:var(--kip-btn-active)}
.kip-cancel{padding:11px 14px;color:var(--kip-body);font-size:15px}
.kip-cancel:hover{background:var(--kip-side);color:var(--kip-text)}
.kip-delete-link{margin-left:auto;padding:11px 14px;color:var(--kip-danger);font-size:14px;font-weight:500}
.kip-delete-link:hover{background:var(--kip-danger-soft);color:var(--kip-danger)}
.kip-meta{border:1px solid var(--kip-line);padding:22px 24px;display:flex;flex-direction:column;gap:14px}
.kip-meta dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:10px 16px;font-size:14px}
.kip-meta dt{color:var(--kip-faint);font-family:var(--kip-mono);font-size:13px}
.kip-meta dd{margin:0;font-family:var(--kip-mono);font-size:13px}
.kip-two-col{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:32px;align-items:start}
@media (max-width:799px){
  .kip-form{padding:0;border:0;background:transparent;max-width:none;gap:18px}
  .kip-formbar{position:fixed;left:0;right:0;bottom:0;z-index:15;margin:0;padding:14px 20px 22px;background:var(--kip-bg);border-top:1px solid var(--kip-line)}
  .kip-formbar .kip-cancel{display:flex;align-items:center;min-height:50px;border:1px solid var(--kip-btn-line);padding:0 18px}
  .kip-formbar .kip-submit{flex:1;min-height:50px;font-size:16px}
  .kip-formbar .kip-delete-link{display:none} /* deletes stay reachable from every browse row */
  .kip-main{padding-bottom:110px} /* clearance for the fixed action bar */
  .kip-two-col{grid-template-columns:1fr;gap:20px}
}

.kip-confirm{width:100%;max-width:540px;background:var(--kip-panel);border:1px solid var(--kip-line);padding:40px;display:flex;flex-direction:column;gap:24px;box-shadow:0 24px 48px -28px rgba(28,35,32,.3);margin:auto}
.kip-confirm-head{display:flex;flex-direction:column;gap:10px}
.kip-confirm-kicker{margin:0;font-size:13px;color:var(--kip-danger);font-weight:600}
.kip-confirm h1{font-size:36px;line-height:1.15}
.kip-confirm-lede{margin:0;font-size:15px;line-height:1.55;color:var(--kip-body)}
.kip-confirm dl{margin:0;padding:18px 20px;background:var(--kip-head);border:1px solid var(--kip-line-soft);display:grid;grid-template-columns:auto 1fr;gap:10px 18px;font-size:14px}
.kip-confirm dt{color:var(--kip-faint);font-family:var(--kip-mono);font-size:13px}
.kip-confirm dd{margin:0;min-width:0;overflow-wrap:anywhere}
.kip-confirm-actions{display:flex;gap:10px}
.kip-confirm-actions button{flex:1;min-height:48px;padding:12px 20px;border:0;border-radius:0;background:var(--kip-danger-btn);color:#fff;font:inherit;font-size:15px;font-weight:500;cursor:pointer}
.kip-confirm-actions button:hover{background:var(--kip-danger-btn-hover)}
.kip-confirm-actions button:active{background:var(--kip-danger-btn-active)}
.kip-confirm-actions a{flex:1;min-height:48px;display:flex;align-items:center;justify-content:center;padding:12px 20px;border:1px solid var(--kip-btn-line);color:var(--kip-text);font-size:15px;font-weight:500}
.kip-confirm-actions a:hover{border-color:var(--kip-accent);color:var(--kip-accent)}

.kip-filters{background:var(--kip-panel);border:1px solid var(--kip-line)}
.kip-filters > summary{display:flex;justify-content:space-between;align-items:center;gap:12px;min-height:50px;padding:0 16px;cursor:pointer;font-size:15px;font-weight:600;color:var(--kip-text);list-style:none}
.kip-filters > summary::-webkit-details-marker{display:none}
.kip-filters[open] > summary{border-bottom:1px solid var(--kip-line-soft)}
.kip-filters-active{font-weight:500;font-size:13px;color:var(--kip-accent)}
@supports selector(.kip-filters::details-content){
  @media (min-width:800px){
    .kip-filters > summary{display:none}
    .kip-filters::details-content{content-visibility:visible;display:block}
  }
}
.kip-filters-form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px;padding:18px 20px}
.kip-filters-form label{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:600}
.kip-filters-form select,.kip-filters-form input[type=text],.kip-filters-form input[type=number]{width:auto;min-width:110px;padding:9px 12px;font-weight:400;font-size:14px}
.kip-filters-check{display:flex;align-items:center;gap:8px;min-height:44px;padding:0 4px;font-size:14px;font-weight:400 !important;cursor:pointer}
.kip-filters-check input{width:18px;height:18px;margin:0;accent-color:var(--kip-btn)}
.kip-filters-actions{display:flex;gap:6px}
.kip-filters-actions button{padding:10px 18px;border:0;border-radius:0;background:var(--kip-btn);color:#fff;font:inherit;font-size:14px;font-weight:500;cursor:pointer}
.kip-filters-actions button:hover{background:var(--kip-btn-hover)}
.kip-filters-actions a{display:flex;align-items:center;padding:10px 12px;color:var(--kip-body);font-size:14px}
.kip-filters-actions a:hover{background:var(--kip-side);color:var(--kip-text)}
.kip-filters-note{margin:0;padding:0 20px 16px}

.kip-tabs{display:flex;gap:4px;padding:4px;background:var(--kip-side);align-self:flex-start}
.kip-tab{padding:8px 16px;color:var(--kip-body);font-size:14px;font-weight:500}
.kip-tab:hover{color:var(--kip-text)}
.kip-tab[aria-current="page"]{background:var(--kip-panel);box-shadow:0 1px 2px rgba(28,35,32,.1);color:var(--kip-text);font-weight:600}

.kip-schema-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:24px;align-items:start}
@media (max-width:799px){.kip-schema-grid{grid-template-columns:1fr}}
.kip-colname{font-family:var(--kip-mono);font-size:13px;font-weight:600}
.kip-pk{margin-left:6px;padding:1px 7px;background:var(--kip-btn);color:#fff;font-family:system-ui,sans-serif;font-size:11px;font-weight:600}
.kip-create{margin:0;display:flex;flex-direction:column;gap:10px}
.kip-create figcaption{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--kip-faint)}
.kip-create pre{margin:0;padding:22px 24px;background:var(--kip-code-bg);color:var(--kip-code-text);font-family:var(--kip-mono);font-size:13px;line-height:1.75;overflow:auto;white-space:pre}
.kip-sql-t{color:var(--kip-code-type)}

/* tables become cards below 800px: the markup stays a real table */
@media (max-width:799px){
  .kip-tablewrap{background:transparent;border:0;overflow:visible}
  .kip-tablewrap table,.kip-tablewrap tbody,.kip-tablewrap tr,.kip-tablewrap td{display:block}
  .kip-tablewrap thead{display:none}
  .kip-tablewrap tr{background:var(--kip-panel);border:1px solid var(--kip-line);margin-bottom:12px}
  .kip-tablewrap tr:last-child{margin-bottom:0}
  .kip-tablewrap td{display:flex;justify-content:space-between;gap:16px;max-width:none;padding:10px 14px;border-bottom:1px solid var(--kip-line-soft);white-space:normal;overflow:visible}
  .kip-tablewrap td:last-child{border-bottom:0}
  .kip-tablewrap td::before{content:attr(data-label);flex:none;padding-top:2px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--kip-faint)}
  .kip-tablewrap td:not([data-label])::before{content:none}
  .kip-tablewrap td.kip-rowactions{justify-content:stretch;gap:0;padding:0}
  .kip-tablewrap td.kip-rowactions a{flex:1;display:flex;align-items:center;justify-content:center;min-height:44px} /* full-width tap halves, mock M2 */
}
  </style>
</head>
<body>
<header class="kip-topbar">
  <a class="kip-brand" href="/admin"><span class="kip-brand-name">Kip</span><span class="kip-brand-tag">Admin</span></a>
</header>
<details class="kip-sidebar">
  <summary class="kip-menu-btn"><span class="kip-when-closed">Menu</span><span class="kip-when-open">Close</span></summary>
  <a class="kip-brand" href="/admin"><span class="kip-brand-name">Kip</span><span class="kip-brand-tag">Admin</span></a>
  <nav class="kip-nav" aria-label="Tables">
    <p class="kip-nav-title">Tables</p>
    <?php foreach ($tables as $t => $count): ?>
    <a class="kip-nav-link" href="/admin/browse/<?= $this->e($t) ?>"<?= $t === $activeTable ? ' aria-current="page"' : '' ?>>
      <span class="kip-nav-name"><?= $this->e($t) ?></span>
      <span class="kip-nav-count"><?= $this->e(number_format((int) $count)) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <nav class="kip-nav" aria-label="Inspect">
    <p class="kip-nav-title">Inspect</p>
    <a class="kip-nav-link" href="/admin/sql"<?= $inspect === 'sql' ? ' aria-current="page"' : '' ?>>SQL browser</a>
    <a class="kip-nav-link" href="/admin/logs"<?= $inspect === 'logs' ? ' aria-current="page"' : '' ?>>Audit log</a>
  </nav>
  <div class="kip-identity">
    <form class="kip-theme" method="post" action="/admin/theme" aria-label="Appearance">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <input type="hidden" name="back" value="<?= $this->e($back) ?>">
      <p class="kip-nav-title">Appearance</p>
      <div class="kip-theme-buttons">
        <button type="submit" name="theme" value="auto" aria-pressed="<?= $theme === '' ? 'true' : 'false' ?>">Auto</button>
        <button type="submit" name="theme" value="light" aria-pressed="<?= $theme === 'light' ? 'true' : 'false' ?>">Light</button>
        <button type="submit" name="theme" value="dark" aria-pressed="<?= $theme === 'dark' ? 'true' : 'false' ?>">Dark</button>
      </div>
    </form>
    <p class="kip-signed-in">Signed in<?= $email === '' ? '' : ' as <strong>' . $this->e($email) . '</strong>' ?></p>
    <a class="kip-back" href="/">&larr; Back to site</a>
  </div>
</details>
<main class="kip-main" id="kip-main"><?= $content ?></main>
</body>
</html>
