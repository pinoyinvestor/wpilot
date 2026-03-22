// WPilot Scroll Animations
// Built by Weblease
(function(){
var s=document.createElement('style');
s.textContent='[data-wpi-animate]{opacity:0;transform:translateY(20px);transition:opacity var(--wpi-dur,.6s) ease,transform var(--wpi-dur,.6s) ease}[data-wpi-animate=fade-in]{transform:none}[data-wpi-animate=fade-left]{transform:translateX(-30px)}[data-wpi-animate=fade-right]{transform:translateX(30px)}[data-wpi-animate=zoom-in]{transform:scale(.85)}[data-wpi-animate=slide-up]{transform:translateY(40px)}[data-wpi-animate].wpi-visible{opacity:1!important;transform:none!important}@media(prefers-reduced-motion:reduce){[data-wpi-animate]{opacity:1!important;transform:none!important;transition:none!important}}';
document.head.appendChild(s);
if(window.matchMedia('(prefers-reduced-motion:reduce)').matches)return;
function init(){
var els=document.querySelectorAll('[data-wpi-animate]');
if(!els.length)return;
var obs=new IntersectionObserver(function(entries){
entries.forEach(function(e){
if(!e.isIntersecting)return;
var el=e.target,d=parseInt(el.getAttribute('data-wpi-delay'))||0,
dur=parseInt(el.getAttribute('data-wpi-duration'))||600,
st=parseInt(el.getAttribute('data-wpi-stagger'))||0;
el.style.setProperty('--wpi-dur',dur+'ms');
if(st){Array.from(el.children).forEach(function(c,i){
c.style.cssText='opacity:0;transform:translateY(20px);transition:opacity '+dur+'ms ease,transform '+dur+'ms ease';
setTimeout(function(){c.style.opacity='1';c.style.transform='none'},d+i*st)});
el.style.opacity='1';el.style.transform='none'}
else setTimeout(function(){el.classList.add('wpi-visible')},d);
obs.unobserve(el)})},{threshold:.1});
els.forEach(function(el){obs.observe(el)})}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init()
})();
