import './bootstrap';

window.addEventListener('load', () => {
  // Handle startup events

  // Handle clipboard
  const copyToClipboardElements = [...document.querySelectorAll('[data-clipboard-text]')];
  copyToClipboardElements.forEach((element) => {
    element.addEventListener('click', (event) => {
      event.preventDefault();
      const text = event.target.getAttribute('data-clipboard-text');
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
      }
      else {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
      }

      const floatingElement = document.createElement('div');
      floatingElement.innerText = element.innerText;
      floatingElement.style.pointerEvents = 'none';
      floatingElement.style.position = 'absolute';
      floatingElement.style.top = `${event.clientY}px`;
      floatingElement.style.left = `${event.clientX}px`;

      document.body.appendChild(floatingElement);
      let opacity = 1;
      const int = setInterval(() => {
        floatingElement.style.top = `${floatingElement.offsetTop - 1}px`;
        floatingElement.style.opacity = opacity = opacity - 0.01;
        if (opacity <= 0) {
          document.body.removeChild(floatingElement);
          clearInterval(int);
        }
      }, 10);
    });
  });

  
});