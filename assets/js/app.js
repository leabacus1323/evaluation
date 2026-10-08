(function(){
  document.querySelectorAll('[data-confirm]').forEach(function(el){
    el.addEventListener('click',function(e){
      if(!confirm(el.getAttribute('data-confirm')||'Are you sure?')) e.preventDefault();
    });
  });
  const search=document.querySelector('[data-table-search]');
  if(search){
    const table=document.querySelector(search.getAttribute('data-table-search'));
    if(table){
      search.addEventListener('input',function(){
        const q=this.value.toLowerCase().trim();
        table.querySelectorAll('tbody tr').forEach(function(row){
          row.style.display=row.textContent.toLowerCase().includes(q)?'':'none';
        });
      });
    }
  }
})();
