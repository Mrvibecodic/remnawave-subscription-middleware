window.cisRegionByName = (function () {
    'use strict';
    var nrm = function (x) { return String(x || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim(); };
    var RTYPES = /\b(oblast|oblasti|oblisi|oblysy|voblasts|voblasc|voblast|region|respublikasi|republic|respublika|kray|krai|krayi|province|viloyati|viloyat|city|gorod|autonomous|avtonomnyy|okrug|district|rayon|raion|municipality|mkhare|qalasy|shahri|economic|of|the|a|s|s r|ao|aok|okr|skaya|skiy|g)\b/g;
    var strp = function (x) { return nrm(x).replace(RTYPES, ' ').replace(/\s+/g, ' ').trim(); };
    return function (M, cc, sub) {
        if (!M || !M.a || !M.r || !sub) return null;
        var id = M.a[cc + '|' + nrm(sub)] || M.a[cc + '|s:' + strp(sub)];
        return id && M.r[id] ? id : null;
    };
})();
