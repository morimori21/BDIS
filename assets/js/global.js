// document.addEventListener('keydown', function(e) {
//     const tag = e.target.tagName.toLowerCase();
//     if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
//         // Allow inside inputs, textareas, selects
//         if (tag !== 'input' && tag !== 'textarea' && tag !== 'select') {
//             e.preventDefault();
//         }
//     }
// });

// // Prevent right-click context menu globally
// document.addEventListener('contextmenu', function(e) {
//     const tag = e.target.tagName.toLowerCase();
//     if (tag !== 'input' && tag !== 'textarea' && tag !== 'select') {
//         e.preventDefault();
//     }
// });

// // Prevent mouse drag selection globally
// document.addEventListener('mousedown', function(e) {
//     const tag = e.target.tagName.toLowerCase();
//     if (tag !== 'input' && tag !== 'textarea' && tag !== 'select') {
//         e.preventDefault();
//     }
// });