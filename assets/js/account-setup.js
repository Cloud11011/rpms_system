(() => {
    'use strict';
    try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme');}catch(_){/* Storage is optional. */}
    const token=location.hash.slice(1),field=document.querySelector('[name="token"]');
    if(field && /^[a-f0-9]{64}$/.test(token))field.value=token;
    if(location.hash)history.replaceState(null,'',location.pathname);
})();
