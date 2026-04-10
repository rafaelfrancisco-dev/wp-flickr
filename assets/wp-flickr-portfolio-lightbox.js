(function () {
	'use strict';

	if (typeof wpfpLightbox === 'undefined') {
		return;
	}

	var overlay = null;
	var imageEl = null;
	var exifBar = null;
	var flickrLink = null;
	var closeBtn = null;
	var prevBtn = null;
	var nextBtn = null;
	var loaderEl = null;
	var currentLinks = [];
	var currentIndex = 0;
	var exifCache = {};

	function buildOverlay() {
		if (overlay) {
			return;
		}

		overlay = document.createElement('div');
		overlay.className = 'wpfp-lightbox-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('aria-label', wpfpLightbox.labels ? wpfpLightbox.labels.lightbox : 'Image lightbox');

		closeBtn = document.createElement('button');
		closeBtn.className = 'wpfp-lightbox-close';
		closeBtn.setAttribute('aria-label', 'Close');
		closeBtn.innerHTML = '&times;';
		closeBtn.addEventListener('click', close);

		flickrLink = document.createElement('a');
		flickrLink.className = 'wpfp-lightbox-flickr-link';
		flickrLink.target = '_blank';
		flickrLink.rel = 'noopener noreferrer';
		flickrLink.textContent = wpfpLightbox.labels ? wpfpLightbox.labels.viewOnFlickr : 'View on Flickr';
		flickrLink.style.display = 'none';

		var headerBar = document.createElement('div');
		headerBar.className = 'wpfp-lightbox-header';
		headerBar.appendChild(closeBtn);
		headerBar.appendChild(flickrLink);

		prevBtn = document.createElement('button');
		prevBtn.className = 'wpfp-lightbox-nav wpfp-lightbox-prev';
		prevBtn.setAttribute('aria-label', 'Previous');
		prevBtn.innerHTML = '&#8249;';
		prevBtn.addEventListener('click', function () { navigate(-1); });

		nextBtn = document.createElement('button');
		nextBtn.className = 'wpfp-lightbox-nav wpfp-lightbox-next';
		nextBtn.setAttribute('aria-label', 'Next');
		nextBtn.innerHTML = '&#8250;';
		nextBtn.addEventListener('click', function () { navigate(1); });

		var imgContainer = document.createElement('div');
		imgContainer.className = 'wpfp-lightbox-image-container';

		loaderEl = document.createElement('div');
		loaderEl.className = 'wpfp-lightbox-loader';
		loaderEl.innerHTML = '<div class="wpfp-lightbox-spinner"></div>';
		loaderEl.style.display = 'none';

		imageEl = document.createElement('img');
		imageEl.className = 'wpfp-lightbox-image';
		imageEl.alt = '';

		imgContainer.appendChild(loaderEl);
		imgContainer.appendChild(imageEl);

		exifBar = document.createElement('div');
		exifBar.className = 'wpfp-lightbox-exif';

		overlay.appendChild(headerBar);
		overlay.appendChild(prevBtn);
		overlay.appendChild(imgContainer);
		overlay.appendChild(nextBtn);
		overlay.appendChild(exifBar);

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay || e.target === imgContainer) {
				close();
			}
		});

		document.body.appendChild(overlay);
	}

	function open(linkEl) {
		buildOverlay();

		var gallery = linkEl.closest('.wpfp-gallery');
		currentLinks = gallery ? Array.prototype.slice.call(gallery.querySelectorAll('a[data-wpfp-photo-id]')) : [linkEl];
		currentIndex = currentLinks.indexOf(linkEl);
		if (currentIndex < 0) {
			currentIndex = 0;
		}

		showCurrent();
		overlay.classList.add('wpfp-lightbox-active');
		document.body.style.overflow = 'hidden';
	}

	function close() {
		if (!overlay) {
			return;
		}
		overlay.classList.remove('wpfp-lightbox-active');
		document.body.style.overflow = '';
		imageEl.src = '';
		exifBar.innerHTML = '';
		flickrLink.style.display = 'none';
	}

	function navigate(direction) {
		currentIndex += direction;
		if (currentIndex < 0) {
			currentIndex = currentLinks.length - 1;
		}
		if (currentIndex >= currentLinks.length) {
			currentIndex = 0;
		}
		showCurrent();
	}

	function showCurrent() {
		var link = currentLinks[currentIndex];
		if (!link) {
			return;
		}

		var fullUrl = link.getAttribute('data-wpfp-full-url') || link.getAttribute('href') || '';
		var title = link.getAttribute('data-wpfp-title') || '';
		var photoId = link.getAttribute('data-wpfp-photo-id') || '';
		var owner = link.getAttribute('data-wpfp-owner') || '';

		prevBtn.style.display = currentLinks.length > 1 ? '' : 'none';
		nextBtn.style.display = currentLinks.length > 1 ? '' : 'none';

		exifBar.innerHTML = '';
		flickrLink.style.display = 'none';

		imageEl.src = '';
		imageEl.alt = '';
		loaderEl.style.display = '';

		var tempImg = new Image();
		tempImg.onload = function () {
			imageEl.alt = title;
			imageEl.src = fullUrl;
			loaderEl.style.display = 'none';
		};
		tempImg.onerror = function () {
			imageEl.alt = title;
			imageEl.src = fullUrl;
			loaderEl.style.display = 'none';
		};
		tempImg.src = fullUrl;

		if (photoId) {
			fetchExif(photoId, owner);
		}
	}

	function fetchExif(photoId, owner) {
		if (exifCache[photoId]) {
			renderExif(exifCache[photoId], owner);
			return;
		}

		var formData = new FormData();
		formData.append('action', 'wpfp_get_exif');
		formData.append('nonce', wpfpLightbox.nonce);
		formData.append('photo_id', photoId);

		exifBar.innerHTML = '<span class="wpfp-lightbox-exif-loading">' + (wpfpLightbox.labels ? wpfpLightbox.labels.loadingExif : 'Loading camera info\u2026') + '</span>';

		fetch(wpfpLightbox.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		})
		.then(function (res) { return res.json(); })
		.then(function (json) {
			if (json.success && json.data) {
				exifCache[photoId] = json.data;
				renderExif(json.data, owner);
			} else {
				exifBar.innerHTML = '';
			}
		})
		.catch(function () {
			exifBar.innerHTML = '';
		});
	}

	function renderExif(data, owner) {
		var parts = [];

		if (data.camera) {
			parts.push(htmlLabel('Camera', data.camera));
		}
		if (data.lens) {
			parts.push(htmlLabel('Lens', data.lens));
		}
		if (data.aperture) {
			parts.push(htmlLabel('Aperture', 'f/' + data.aperture));
		}
		if (data.shutter) {
			parts.push(htmlLabel('Shutter', data.shutter + 's'));
		}
		if (data.iso) {
			parts.push(htmlLabel('ISO', data.iso));
		}
		if (data.focal) {
			parts.push(htmlLabel('Focal', data.focal));
		}
		if (data.latitude && data.longitude) {
			parts.push(htmlLabel('Location', data.latitude + ', ' + data.longitude));
		}

		if (parts.length === 0) {
			exifBar.innerHTML = '';
			return;
		}

		exifBar.innerHTML = parts.join('');

		if (data.flickrUrl) {
			flickrLink.href = data.flickrUrl;
			flickrLink.style.display = '';
		} else if (owner) {
			var photoId = '';
			var currentLink = currentLinks[currentIndex];
			if (currentLink) {
				photoId = currentLink.getAttribute('data-wpfp-photo-id') || '';
			}
			if (photoId) {
				flickrLink.href = 'https://www.flickr.com/photos/' + owner + '/' + photoId + '/';
				flickrLink.style.display = '';
			}
		}
	}

	function htmlLabel(label, value) {
		return '<span class="wpfp-exif-item"><span class="wpfp-exif-label">' + escapeHtml(label) + '</span><span class="wpfp-exif-value">' + escapeHtml(value) + '</span></span>';
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	document.addEventListener('click', function (e) {
		var link = e.target.closest('a[data-wpfp-photo-id]');
		if (!link) {
			return;
		}
		e.preventDefault();
		open(link);
	});

	document.addEventListener('keydown', function (e) {
		if (!overlay || !overlay.classList.contains('wpfp-lightbox-active')) {
			return;
		}
		if (e.key === 'Escape') {
			close();
		} else if (e.key === 'ArrowLeft') {
			navigate(-1);
		} else if (e.key === 'ArrowRight') {
			navigate(1);
		}
	});
})();
