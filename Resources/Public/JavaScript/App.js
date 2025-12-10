window.addEventListener('load', () => {
    let historyContainer, loadMore, nextPage;

    /** @type {HTMLFormElement} */
    const filterForm = document.getElementById('historyFilterForm');
    /** @type {HTMLSelectElement} */
    const siteIdentifier = document.getElementById('siteIdentifier');
    /** @type {HTMLSelectElement} */
    const accountIdentifier = document.getElementById('accountIdentifier');

    if (siteIdentifier) {
        siteIdentifier.addEventListener('change', () => filterForm.submit());
    }

    if (accountIdentifier) {
        accountIdentifier.addEventListener('change', () => filterForm.submit());
    }

    historyContainer = document.querySelector('.neos-history');
    loadMore = document.getElementById('loadMore');

    if (!historyContainer || !loadMore) {
        return;
    }

    nextPage = loadMore.dataset.neosHistoryNextpage;

    const loadMoreButton = loadMore.querySelector('button');
    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', () => {
            loadMoreButton.disabled = true;
            fetch(nextPage)
                .then((response) => response.text())
                .then((data) => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(data, 'text/html');

                    const nextLoadMore = doc.getElementById('loadMore');
                    nextPage = nextLoadMore ? nextLoadMore.dataset.neosHistoryNextpage : undefined;

                    if (typeof nextPage === 'undefined') {
                        loadMore.style.display = 'none';
                    }

                    const days = doc.querySelectorAll('.neos-history-day');
                    days.forEach((day) => {
                        const date = day.dataset.date;
                        const existingDay = document.querySelector(`[data-date="${date}"]`);

                        if (existingDay) {
                            const events = day.querySelectorAll('.neos-history-event');
                            const existingEventsContainer = existingDay.querySelector('.neos-history-events');
                            events.forEach((event) => {
                                existingEventsContainer.appendChild(event);
                            });
                        } else {
                            historyContainer.appendChild(day);
                        }
                    });
                })
                .finally(() => {
                    loadMoreButton.disabled = false;
                });
        });
    }
});
