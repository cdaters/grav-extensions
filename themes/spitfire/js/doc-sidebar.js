(() => {
  'use strict';

  const sidebar = document.querySelector('.sfng-doc-sidebar');
  const activePage = sidebar?.querySelector('[aria-current="page"]');

  if (sidebar instanceof HTMLElement && activePage instanceof HTMLElement) {
    const sidebarBounds = sidebar.getBoundingClientRect();
    const activeBounds = activePage.getBoundingClientRect();
    if (activeBounds.top < sidebarBounds.top || activeBounds.bottom > sidebarBounds.bottom) {
      sidebar.scrollTop += activeBounds.top - sidebarBounds.top
        - (sidebar.clientHeight / 2)
        + (activeBounds.height / 2);
    }
  }
})();
