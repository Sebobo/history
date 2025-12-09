window.addEventListener('load', () => {
    let historyContainer, loadMore, nextPage;

    // Handle select changes
    const siteIdentifier = document.getElementById('siteIdentifier');
    const accountIdentifier = document.getElementById('accountIdentifier');

    if (siteIdentifier) {
        siteIdentifier.addEventListener('change', function () {
            this.form.submit();
        });
    }

    if (accountIdentifier) {
        accountIdentifier.addEventListener('change', function () {
            this.form.submit();
        });
    }

    historyContainer = document.querySelector('.neos-history');
    loadMore = document.querySelector('.loadMore');

    if (!historyContainer || !loadMore) {
        return;
    }

    nextPage = loadMore.dataset.neosHistoryNextpage;

    const loadMoreButton = loadMore.querySelector('button');
    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', function () {
            fetch(nextPage)
                .then((response) => response.text())
                .then((data) => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(data, 'text/html');

                    const nextLoadMore = doc.querySelector('.loadMore');
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
                });
        });
    }
});
