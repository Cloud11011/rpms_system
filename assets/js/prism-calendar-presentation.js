/* Presentation only: no API, storage, date arithmetic, navigation or permission adapter. */
(() => {
    'use strict';
    const grids = '#calendarDays,#adviserCalendarDays,#dashboardCalendarGrid,#monthGrid,#yearCalendarView';
    function decorate(grid) {
        grid.querySelectorAll('button').forEach(cell => {
            const today = cell.dataset.today === 'true' || cell.classList.contains('today') || cell.classList.contains('active-day');
            const selected = cell.dataset.selectedDay === 'true' || cell.classList.contains('selected');
            if (today) cell.setAttribute('aria-current', 'date');
            else cell.removeAttribute('aria-current');
            cell.setAttribute('aria-pressed', String(selected));
        });
    }
    document.addEventListener('DOMContentLoaded', () => {
        // Keep every existing navigation button and listener; normalize only its glyph.
        document.querySelectorAll('#prevBtn,#previousMonth,#dashboardPreviousMonth,[data-deadline-month="-1"]').forEach(button => {
            button.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>';
        });
        document.querySelectorAll('#nextBtn,#nextMonth,#dashboardNextMonth,[data-deadline-month="1"]').forEach(button => {
            button.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>';
        });
        document.querySelectorAll(grids).forEach(grid => {
            // Student calendars already render their own legend with the portal styling.
            // Avoid adding a second, differently styled legend above it.
            if (grid.nextElementSibling?.classList.contains('calendar-legend')) {
                decorate(grid);
                new MutationObserver(() => decorate(grid)).observe(grid, {
                    childList: true, subtree: true, attributes: true,
                    attributeFilter: ['class', 'data-today', 'data-selected-day']
                });
                return;
            }
            const legend = document.createElement('div');
            legend.className = 'prism-calendar-legend';
            if (grid.id === 'yearCalendarView') legend.classList.add('is-year');
            legend.setAttribute('aria-label', 'Calendar legend');
            legend.innerHTML = '<span><b aria-hidden="true"></b>Official deadline</span>'
                + '<span class="is-today"><b aria-hidden="true"></b>Today</span>'
                + '<span class="is-selected"><b aria-hidden="true"></b>Selected date</span>';
            grid.after(legend);
            decorate(grid);
            new MutationObserver(() => decorate(grid)).observe(grid, {
                childList: true, subtree: true, attributes: true,
                attributeFilter: ['class', 'data-today', 'data-selected-day']
            });
        });
    });
})();
