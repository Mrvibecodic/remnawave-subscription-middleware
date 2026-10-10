    <style>
        .mc-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start}
        @media(max-width:720px){.mc-grid{grid-template-columns:1fr}}
        .sqcfg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.7rem 1rem;align-items:end}
        .sqcfg-grid label{font-weight:600}
        .sqcfg-sel{appearance:none;-webkit-appearance:none;-moz-appearance:none;padding-right:2.2rem;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .75rem center;background-size:.95rem}
        #sqEditModal label:not(.sq-item){font-weight:600}
        .card label{display:block;margin-bottom:.35rem;font-weight:600;font-size:.85rem}
        .sq-tag{display:inline-block;background:var(--bg2);border:1px solid var(--line);border-radius:6px;padding:.08rem .45rem;font-size:.74rem;margin:.1rem .25rem .1rem 0;white-space:nowrap}
        .sq-manual{padding-top:.4rem;padding-bottom:.4rem}
        .sq-manual .sq-mtxt{display:flex;flex-direction:column;justify-content:center;gap:.05rem;flex:1;min-width:0}
        .sq-manual .sq-n{flex:none;line-height:1.15;font-size:.86rem}
    </style>
