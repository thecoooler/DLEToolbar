<?php

if (!defined('DATALIFEENGINE')) {
    http_response_code(403);
    die('Hacking attempt!');
}
?>
<style>
    :root {
        color-scheme: light;
        --dlt-surface: #fff;
        --dlt-foreground: #27272a;
        --dlt-muted: #52525b;
        --dlt-border: #e4e4e7;
        --dlt-hover: #f4f4f5;
        --dlt-stripe: #fafafa;
        --dlt-shadow: 0 8px 30px rgb(24 24 27 / 10%), 0 2px 6px rgb(24 24 27 / 5%);
        --dlt-danger: #b91c1c;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            color-scheme: dark;
            --dlt-surface: #18181b;
            --dlt-foreground: #fafafa;
            --dlt-muted: #a1a1aa;
            --dlt-border: #3f3f46;
            --dlt-hover: #27272a;
            --dlt-stripe: #1c1c20;
            --dlt-shadow: 0 8px 30px rgb(0 0 0 / 25%), 0 2px 6px rgb(0 0 0 / 15%);
            --dlt-danger: #fca5a5;
        }
    }
    html, body { min-height: 100%; background: var(--dlt-surface) !important; color: var(--dlt-foreground); }
    body { font: 500 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
    body > .navbar, .page-container .sidebar-main, .page-header, .content > .footer { display: none !important; }
    .page-container, .page-content, .content-wrapper { display: block !important; width: 100% !important; min-height: 0 !important; background: var(--dlt-surface) !important; }
    .content { padding: 0 !important; }
    .content > .alert { margin: 16px; border: 1px solid var(--dlt-border); border-radius: 10px; background: var(--dlt-hover); color: var(--dlt-foreground); box-shadow: none; }
    .content > .alert-danger { border-color: var(--dlt-danger); color: var(--dlt-danger); }
    .content > .alert pre { background: var(--dlt-surface); color: var(--dlt-foreground); border-color: var(--dlt-border); }
    #dlt-settings-form { color: var(--dlt-foreground); }
    #dlt-settings-form .panel { margin: 0; border: 0; border-radius: 0; background: var(--dlt-surface); box-shadow: none; }
    #dlt-settings-form .panel-heading { padding: 6px; border: 0; background: var(--dlt-surface); border-radius: 0; }
    #dlt-settings-form .nav-tabs { display: flex; flex-wrap: wrap; gap: 4px; margin: 0; border: 0; background: transparent; }
    #dlt-settings-form .nav-tabs > li { float: none; margin: 0; }
    #dlt-settings-form .nav-tabs > li > a { display: block; margin: 0; padding: 9px 12px; border: 0; border-radius: 8px; background: transparent; color: var(--dlt-muted); font-weight: 600; }
    #dlt-settings-form .nav-tabs > li > a:hover { background: var(--dlt-hover); color: var(--dlt-foreground); }
    #dlt-settings-form .nav-tabs > li.active > a { background: var(--dlt-hover); color: var(--dlt-foreground); }
    #dlt-settings-form .panel-body { padding: 16px; }
    #dlt-settings-form .panel-body.border-top { padding-top: 0; border: 0; }
    #dlt-settings-form .text-muted { color: var(--dlt-muted) !important; }
    #dlt-settings-form .table-responsive { border: 0; }
    #dlt-settings-form .table { width: 100%; margin: 0; color: var(--dlt-foreground); table-layout: fixed; }
    #dlt-settings-form .table .dlt-handle-cell { width: 40px; }
    #dlt-settings-form .table .dlt-actions-cell { width: 100px; }
    #dlt-settings-form .table > thead > tr > th { padding: 10px 12px; border: 0; border-top: 1px solid var(--dlt-border); border-bottom: 1px solid var(--dlt-border); background: var(--dlt-stripe); color: var(--dlt-muted); font-size: 11px; font-weight: 600; }
    #dlt-settings-form .table > tbody > tr > td { padding: 12px; border: 0; border-bottom: 1px solid var(--dlt-border); background: var(--dlt-surface); vertical-align: middle; white-space: normal; overflow-wrap: anywhere; }
    #dlt-settings-form .table > tbody > tr:last-child > td { border-bottom: 0; }
    #dlt-settings-form .table > tbody > tr:hover > td { background: var(--dlt-hover); }
    #dlt-settings-form td.dlt-handle-cell { color: var(--dlt-muted); }
    #dlt-settings-form .dlt-component-label { font-weight: 600; }
    #dlt-settings-form .dlt-component-label + .text-muted { margin-top: 3px; line-height: 1.4; }
    #dlt-settings-form .dlt-disabled td:nth-child(2) { opacity: 1; }
    #dlt-settings-form .dlt-disabled td:nth-child(2) > div { opacity: .55; }
    #dlt-settings-form .panel-footer { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 12px 16px; border: 0; background: var(--dlt-surface); }
    #dlt-settings-form .panel-footer .position-right { margin: 0; }
    #dlt-settings-form .panel-footer [hidden] { display: none !important; }
    #dlt-settings-form .text-warning { color: var(--dlt-muted) !important; }
    #dlt-settings-form .btn { min-height: 34px; padding: 7px 12px; border: 1px solid var(--dlt-border); border-radius: 8px; background: var(--dlt-surface); color: var(--dlt-foreground); box-shadow: none; font: 600 13px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; text-shadow: none; }
    #dlt-settings-form .btn:hover, #dlt-settings-form .btn:focus { border-color: var(--dlt-border); background: var(--dlt-hover); color: var(--dlt-foreground); box-shadow: none; }
    #dlt-settings-form .panel-footer button[type="submit"] { border-color: var(--dlt-foreground); background: var(--dlt-foreground); color: var(--dlt-surface); }
    #dlt-settings-form .panel-footer button[type="submit"]:hover { opacity: .85; }
    #dlt-settings-form .dlt-actions .btn { display: inline-flex; align-items: center; justify-content: center; width: 34px; padding: 0; color: var(--dlt-muted); }
    #dlt-settings-form .btn.bg-danger { color: var(--dlt-danger); }
    #dlt-settings-form :is(button, input, select, a):focus-visible { outline: 2px solid var(--dlt-foreground); outline-offset: 2px; }
    #dlt-settings-form .switchery { border: 1px solid var(--dlt-border) !important; background: var(--dlt-border) !important; box-shadow: none !important; }
    #dlt-settings-form .switch:checked + .switchery { border-color: var(--dlt-foreground) !important; background: var(--dlt-foreground) !important; }
    #dlt-settings-form .switchery > small { background: var(--dlt-surface) !important; box-shadow: 0 1px 3px rgb(0 0 0 / 20%) !important; }
    #dlt-settings-form .switch:focus-visible + .switchery { outline: 2px solid var(--dlt-foreground); outline-offset: 2px; }
    #dlt-settings-form .form-control, #dlt-settings-form .chosen-container-multi .chosen-choices { min-height: 36px; border: 1px solid var(--dlt-border); border-radius: 8px; background: var(--dlt-surface); color: var(--dlt-foreground); box-shadow: none; }
    #dlt-settings-form .form-control:focus, #dlt-settings-form .chosen-container-active .chosen-choices { border-color: var(--dlt-muted); box-shadow: 0 0 0 2px var(--dlt-hover); }
    #dlt-settings-form .bootstrap-select.form-control { padding: 0; border: 0; background: transparent; box-shadow: none; }
    #dlt-settings-form .bootstrap-select > .dropdown-toggle { width: 100%; min-height: 36px; text-align: left; }
    #dlt-settings-form .chosen-container-multi .chosen-choices { padding: 4px 7px; }
    #dlt-settings-form .chosen-container-multi .chosen-choices li.search-choice { border: 1px solid var(--dlt-border); border-radius: 6px; background: var(--dlt-hover); color: var(--dlt-foreground); box-shadow: none; }
    #dlt-settings-form .chosen-container-multi .chosen-choices li.search-field input { color: var(--dlt-foreground); }
    #dlt-settings-form .chosen-container .chosen-drop, #dlt-settings-form .dropdown-menu { border: 1px solid var(--dlt-border); border-radius: 8px; background: var(--dlt-surface); color: var(--dlt-foreground); box-shadow: var(--dlt-shadow); }
    #dlt-settings-form .bootstrap-select .dropdown-menu.inner { border: 0; background: transparent; box-shadow: none; }
    #dlt-settings-form .chosen-container .chosen-results li, #dlt-settings-form .dropdown-menu > li > a { color: var(--dlt-foreground); }
    #dlt-settings-form .chosen-container .chosen-results li.highlighted, #dlt-settings-form .dropdown-menu > li > a:hover, #dlt-settings-form .dropdown-menu > .active > a { background: var(--dlt-hover); color: var(--dlt-foreground); }
    #dlt-settings-form .modal-content { overflow: hidden; border: 1px solid var(--dlt-border); border-radius: 14px; background: var(--dlt-surface); color: var(--dlt-foreground); box-shadow: var(--dlt-shadow); }
    #dlt-settings-form .modal-header, #dlt-settings-form .modal-footer { border-color: var(--dlt-border); background: var(--dlt-surface); color: var(--dlt-foreground); }
    #dlt-settings-form .modal-header { padding-top: 16px; padding-bottom: 16px; }
    #dlt-settings-form .modal-header .close { color: var(--dlt-muted); opacity: 1; text-shadow: none; }
    #dlt-settings-form .modal-body { padding-top: 20px; padding-bottom: 20px; }
    #dlt-settings-form .modal-footer { padding-top: 12px; padding-bottom: 12px; }
    #dlt-settings-form .dlt-icon-choice { border-color: var(--dlt-border); background: var(--dlt-surface); color: var(--dlt-muted); }
    #dlt-settings-form .dlt-icon-choice:hover { background: var(--dlt-hover); color: var(--dlt-foreground); }
    #dlt-settings-form .dlt-icon-choice:has(input:checked) { border-color: var(--dlt-foreground); background: var(--dlt-hover); color: var(--dlt-foreground); }
    #dlt-settings-form .dlt-icon-choice:has(input:focus-visible) { outline-color: var(--dlt-foreground); }
    #dlt-settings-form .has-error .form-control { border-color: var(--dlt-danger); }
    #dlt-settings-form .has-error .help-block { color: var(--dlt-danger); }
    @media (max-width: 480px) {
        .content { padding: 0 !important; }
        #dlt-settings-form .panel-body { padding: 12px; }
        #dlt-settings-form .table > tbody > tr > td { padding: 10px 6px; }
        #dlt-settings-form .dlt-actions { gap: 6px; }
        #dlt-settings-form .panel-footer { padding: 10px 12px; }
    }
</style>
