(function(){
  'use strict';

  const DAY=86400000;
  const ICONS={
    calendar:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    flag:'<path d="M5 21V4m0 1h11l-2 4 2 4H5"/>',
    registration:'<path d="M4 20v-2a4 4 0 0 1 4-4h4"/><circle cx="10" cy="7" r="4"/><path d="M16 19l2 2 4-5"/>',
    deadline:'<circle cx="12" cy="12" r="9"/><path d="M12 6v7l4 2"/>',
    trophy:'<path d="M8 4h8v4a4 4 0 0 1-8 0V4Z"/><path d="M8 6H4v1a4 4 0 0 0 4 4m8-5h4v1a4 4 0 0 1-4 4M12 12v5m-4 3h8"/>',
    star:'<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
    info:'<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/>'
  };

  function el(tag,className,text){
    const node=document.createElement(tag);
    if(className)node.className=className;
    if(text!==undefined&&text!==null)node.textContent=text;
    return node;
  }
  function svgIcon(key,className){
    const span=el('span',className||'wz-event__mark');
    span.innerHTML='<svg class="wz-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+(ICONS[key]||ICONS.calendar)+'</svg>';
    return span;
  }
  function chevron(){
    const span=el('span','wz-subject-chevron');
    span.innerHTML='<svg viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 5 5 5-5 5"/></svg>';
    return span;
  }
  function parseIsoUtc(value){
    const m=String(value||'').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return m?Date.UTC(Number(m[1]),Number(m[2])-1,Number(m[3])):NaN;
  }
  function clamp(n,min,max){return Math.max(min,Math.min(max,n));}
  function intersects(a,b){return !b.length||(a||[]).some(v=>b.includes(String(v)));}
  function reducedMotion(){return window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;}

  function init(root){
    if(root.dataset.wzReady==='1')return;
    root.dataset.wzReady='1';

    const dataEl=root.querySelector('[data-wz-timeline-data]');
    const scroller=root.querySelector('[data-wz-scroller]');
    const list=root.querySelector('[data-wz-list]');
    const filterEmpty=root.querySelector('[data-wz-filter-empty]');
    const modal=root.querySelector('[data-wz-modal]');
    const preview=root.querySelector('[data-wz-preview]');
    const rangeSelect=root.querySelector('[data-wz-range]');
    let config={};
    try{config=JSON.parse(dataEl?dataEl.textContent:'{}');}catch(e){config={};}
    if(!scroller||!Array.isArray(config.subjects))return;

    const startMs=parseIsoUtc(config.bounds&&config.bounds.start);
    const endMs=parseIsoUtc(config.bounds&&config.bounds.end);
    const totalMs=Math.max(DAY,endMs-startMs);
    const coarse=window.matchMedia&&window.matchMedia('(hover: none), (pointer: coarse)').matches;
    const collapsed=new Set();
    const expandedRows=new Set();
    let rangeMonths=coarse?3:12;
    let viewMode='timeline';
    let currentPayload=null;
    let currentAnchor=null;
    let lastFocus=null;
    let previewTimer=null;

    if(rangeSelect){rangeSelect.value=String(rangeMonths);}

    function checked(selector){return Array.from(root.querySelectorAll(selector+':checked')).map(x=>x.value);}
    function filters(){return {subjects:checked('[data-wz-filter-subject]'),grades:checked('[data-wz-filter-grade]'),types:checked('[data-wz-filter-type]')};}
    function matches(event,subjectId,f){
      return (!f.subjects.length||f.subjects.includes(String(subjectId)))&&intersects(event.grades,f.grades)&&(!f.types.length||f.types.includes(String(event.eventTypeId)));
    }
    function percent(date){return clamp((parseIsoUtc(date)-startMs)/totalMs*100,0,100);}
    function eventPosition(event){
      const left=percent(event.start);
      if(!event.isRange||!event.end)return {left:left,width:0};
      const right=clamp((parseIsoUtc(event.end)+DAY-startMs)/totalMs*100,left,100);
      return {left:left,width:Math.max(.35,right-left)};
    }
    function labelWidth(){
      const cell=scroller.querySelector('.wz-sticky-cell');
      return cell?cell.getBoundingClientRect().width:(coarse?172:210);
    }
    function visibleTrackWidth(){return Math.max(120,scroller.clientWidth-labelWidth());}
    function trackWidth(){
      const visible=visibleTrackWidth();
      return Math.max(visible,Math.round(visible*(12/rangeMonths)));
    }
    function centerPercent(){
      const width=parseFloat(scroller.style.getPropertyValue('--wz-track-width'))||trackWidth();
      const visible=visibleTrackWidth();
      return clamp((scroller.scrollLeft+visible/2)/Math.max(1,width),0,1);
    }
    function row(labelNode,trackNode,extra){
      const r=el('div','wz-gridrow'+(extra?' '+extra:''));
      const sticky=el('div','wz-sticky-cell');
      sticky.appendChild(labelNode);r.appendChild(sticky);r.appendChild(trackNode);return r;
    }
    function decorateTrack(track,includeToday){
      track.classList.add('wz-track');
      (config.months||[]).forEach(month=>{
        const line=el('span','wz-month-gridline');line.style.left=month.left+'%';track.appendChild(line);
      });
      if(includeToday&&config.today){const today=el('span','wz-today');today.style.left=percent(config.today)+'%';today.setAttribute('aria-label','Heute');track.appendChild(today);}
    }
    function competitionLabel(comp,hiddenCount,rowKey,isExpanded){
      const box=el('div','wz-competition');
      const main=el('div','wz-competition__main');
      const label=comp.shortName||comp.name||'';
      if(comp.internalUrl){const a=el('a','wz-competition__name',label);a.href=comp.internalUrl;main.appendChild(a);}else main.appendChild(el('span','wz-competition__name',label));
      if(comp.externalUrl){const a=el('a','wz-external','↗');a.href=comp.externalUrl;a.target='_blank';a.rel='noopener noreferrer';a.setAttribute('aria-label','Offizielle Website von '+comp.name);main.appendChild(a);}
      box.appendChild(main);
      if(hiddenCount>0||isExpanded){
        const more=el('button','wz-more-events',isExpanded?'Weniger':('+'+hiddenCount+' weitere'));
        more.type='button';
        more.setAttribute('aria-label',isExpanded?'Weniger Termine anzeigen':hiddenCount+' weitere Termine anzeigen');
        more.addEventListener('click',e=>{
          e.preventDefault();e.stopPropagation();
          if(expandedRows.has(rowKey))expandedRows.delete(rowKey);else expandedRows.add(rowKey);
          render();
        });
        box.appendChild(more);
      }
      return box;
    }
    function subjectLabel(subject,visibleCount){
      const wrap=el('div','wz-subject-label');
      const button=el('button','wz-subject-toggle');
      button.type='button';button.dataset.wzSubjectToggle=subject.id;button.setAttribute('aria-expanded',collapsed.has(subject.id)?'false':'true');
      button.appendChild(chevron());
      const name=el('strong','wz-subject-name',subject.name);button.appendChild(name);
      button.appendChild(el('span','wz-subject-count',String(visibleCount)));
      wrap.appendChild(button);return wrap;
    }
    function shortEventLabel(event){
      const title=String(event.title||'Termin').trim();
      if(title.length<=19)return title;
      const match=title.match(/(?:\b\d+\.\s*Runde\b|Regionalrunde|Landesrunde|Bundesrunde|Finalrunde|Finale|Anmeldeschluss|Anmeldung|Einsendeschluss|Abgabe|Preisverleihung|Wettbewerbswoche|Wettbewerb)/i);
      if(match)return match[0];
      return title.slice(0,18).trimEnd()+'…';
    }
    function layoutEvents(events,width){
      const gap=6;
      const spans=events.map(event=>{
        const pos=eventPosition(event);
        let left=pos.left/100*width;
        let right;
        if(event.isRange){right=left+Math.max(28,pos.width/100*width);}
        else{left-=17;right=left+34;}
        return {event:event,left:left,right:right,lane:0};
      }).sort((a,b)=>a.left-b.left||a.right-b.right||String(a.event.title).localeCompare(String(b.event.title),'de'));
      const laneEnds=[];
      spans.forEach(item=>{
        let lane=0;
        while(lane<laneEnds.length&&item.left<laneEnds[lane]+gap)lane++;
        if(lane===laneEnds.length)laneEnds.push(item.right);else laneEnds[lane]=item.right;
        item.lane=lane;
      });
      return {items:spans,laneCount:Math.max(1,laneEnds.length)};
    }
    function eventButton(event,lane){
      const pos=eventPosition(event);
      const button=el('button','wz-event wz-event--'+(event.displayStyle||'standard')+(event.isRange?' wz-event--range':' wz-event--point'));
      button.type='button';button.dataset.wzEvent=event.id;button.style.left=pos.left+'%';
      button.style.top=(12+lane*34+17)+'px';
      if(event.isRange)button.style.width=pos.width+'%';
      else button.style.transform='translate(-50%,-50%)';
      button.setAttribute('aria-label',(event.title||'Termin')+', '+((event.payload&&event.payload.date)||''));
      button.title=event.title||'';
      button.appendChild(svgIcon(event.icon));
      if(event.isRange)button.appendChild(el('span','wz-event__label',shortEventLabel(event)));
      button.addEventListener('mouseenter',()=>{if(!coarse)showPreview(button,event.payload);});
      button.addEventListener('mouseleave',()=>{if(!coarse)scheduleHidePreview();});
      button.addEventListener('focus',()=>showPreview(button,event.payload));
      button.addEventListener('blur',()=>scheduleHidePreview());
      button.addEventListener('click',e=>{
        e.preventDefault();
        if(coarse)showPreview(button,event.payload);else openModal(event.payload,button);
      });
      return button;
    }

    function visibleEntries(){
      const f=filters();
      const entries=[];
      config.subjects.forEach(subject=>{
        if(f.subjects.length&&!f.subjects.includes(String(subject.id)))return;
        (subject.competitions||[]).forEach(comp=>{
          (comp.events||[]).forEach(event=>{
            if(matches(event,subject.id,f))entries.push({subject:subject,competition:comp,event:event});
          });
        });
      });
      return entries;
    }

    function renderTimeline(entries){
      const width=trackWidth();
      scroller.style.setProperty('--wz-track-width',width+'px');
      const fragment=document.createDocumentFragment();
      const f=filters();

      const axisTrack=el('div','wz-track wz-axis__track');
      const monthPixel=width/12;
      (config.months||[]).forEach((month,index)=>{
        const showLabel=monthPixel>=38||index%3===0;
        const m=el('div','wz-month'+(showLabel?'':' wz-month--label-hidden'),showLabel?month.label:'');
        m.style.left=month.left+'%';m.style.width=month.width+'%';axisTrack.appendChild(m);
      });
      if(config.today){const today=el('span','wz-today wz-today--axis');today.style.left=percent(config.today)+'%';axisTrack.appendChild(today);}
      fragment.appendChild(row(el('div','wz-axis__label','Wettbewerb'),axisTrack,'wz-axis'));

      config.subjects.forEach(subject=>{
        if(f.subjects.length&&!f.subjects.includes(String(subject.id)))return;
        const comps=[];
        (subject.competitions||[]).forEach(comp=>{
          const events=(comp.events||[]).filter(event=>matches(event,subject.id,f));
          if(events.length)comps.push({comp:comp,events:events});
        });
        if(!comps.length)return;

        const subjectTrack=el('div','wz-track');decorateTrack(subjectTrack,false);
        fragment.appendChild(row(subjectLabel(subject,comps.length),subjectTrack,'wz-subject-title'));

        if(!collapsed.has(subject.id)){
          comps.forEach(entry=>{
            const rowKey=String(subject.id)+'::'+String(entry.comp.id||entry.comp.competitionId||entry.comp.name);
            const layout=layoutEvents(entry.events,width);
            const expanded=expandedRows.has(rowKey);
            const maxVisibleLanes=expanded?layout.laneCount:Math.min(3,layout.laneCount);
            const hiddenItems=expanded?[]:layout.items.filter(item=>item.lane>=3);
            const visibleItems=expanded?layout.items:layout.items.filter(item=>item.lane<3);
            const track=el('div','wz-track wz-events-track');decorateTrack(track,true);
            const trackHeight=12+maxVisibleLanes*34+12;
            track.style.height=trackHeight+'px';track.style.minHeight=trackHeight+'px';
            visibleItems.forEach(item=>track.appendChild(eventButton(item.event,item.lane)));
            fragment.appendChild(row(competitionLabel(entry.comp,hiddenItems.length,rowKey,expanded&&layout.laneCount>3),track,'wz-row wz-row--lanes-'+maxVisibleLanes));
          });
        }
      });

      scroller.replaceChildren(fragment);
      bindSubjectToggles();
      updateScrollClass();
    }

    function monthKey(iso){return String(iso||'').slice(0,7);}
    function monthLabel(iso){
      const ms=parseIsoUtc(iso);
      if(!Number.isFinite(ms))return '';
      return new Intl.DateTimeFormat('de-DE',{month:'long',year:'numeric',timeZone:'UTC'}).format(new Date(ms));
    }
    function renderList(entries){
      if(!list)return;
      const unique=new Map();
      entries.forEach(entry=>{
        const key=entry.event.eventId||entry.event.id;
        if(!unique.has(key))unique.set(key,entry);
      });
      const items=Array.from(unique.values()).sort((a,b)=>parseIsoUtc(a.event.start)-parseIsoUtc(b.event.start)||String(a.event.title).localeCompare(String(b.event.title),'de'));
      const fragment=document.createDocumentFragment();
      let currentMonth='';
      items.forEach(entry=>{
        const event=entry.event;
        const key=monthKey(event.start);
        if(key!==currentMonth){
          currentMonth=key;
          fragment.appendChild(el('h3','wz-list__month',monthLabel(event.start)));
        }
        const button=el('button','wz-list__item');button.type='button';
        const date=el('span','wz-list__date',event.payload&&event.payload.date?event.payload.date:event.start);button.appendChild(date);
        const body=el('span','wz-list__body');
        const meta=el('span','wz-list__meta');meta.appendChild(svgIcon(event.icon,'wz-list__icon'));meta.appendChild(el('span','',event.payload&&event.payload.eventType?event.payload.eventType:''));body.appendChild(meta);
        body.appendChild(el('strong','wz-list__title',event.title));
        body.appendChild(el('span','wz-list__competition',entry.competition.name));
        button.appendChild(body);
        button.addEventListener('click',()=>openModal(event.payload,button));
        fragment.appendChild(button);
      });
      list.replaceChildren(fragment);
    }

    function render(options){
      const opts=options||{};
      const anchor=opts.anchor!==undefined?opts.anchor:centerPercent();
      const entries=visibleEntries();
      renderTimeline(entries);
      renderList(entries);
      const hasEvents=entries.length>0;
      if(filterEmpty)filterEmpty.hidden=hasEvents;
      scroller.hidden=!hasEvents||viewMode!=='timeline';
      if(list)list.hidden=!hasEvents||viewMode!=='list';
      root.classList.toggle('is-list-view',viewMode==='list');
      updateViewButtons();
      if(hasEvents&&viewMode==='timeline'&&opts.restore!==false){
        requestAnimationFrame(()=>restoreCenter(anchor));
      }
    }

    function bindSubjectToggles(){
      scroller.querySelectorAll('[data-wz-subject-toggle]').forEach(button=>button.addEventListener('click',()=>{
        const id=button.dataset.wzSubjectToggle;
        if(collapsed.has(id))collapsed.delete(id);else collapsed.add(id);
        render();
      }));
    }

    function link(label,url,external){
      if(!url)return null;
      const a=document.createElement('a');a.href=url;a.textContent=label;
      if(external){a.target='_blank';a.rel='noopener noreferrer';}
      return a;
    }
    function openModal(payload,trigger){
      if(!modal||!payload)return;
      currentPayload=payload;lastFocus=trigger||document.activeElement;
      modal.querySelector('[data-wz-modal-type]').textContent=payload.eventType||'';
      modal.querySelector('[data-wz-modal-title]').textContent=payload.title||'';
      modal.querySelector('[data-wz-modal-competition]').textContent=payload.competition||'';
      modal.querySelector('[data-wz-modal-date]').textContent=payload.date||'';
      modal.querySelector('[data-wz-modal-subjects]').textContent=payload.subjects||'–';
      modal.querySelector('[data-wz-modal-grades]').textContent=payload.grades||'–';
      const noteWrap=modal.querySelector('[data-wz-modal-note-wrap]');
      modal.querySelector('[data-wz-modal-note]').textContent=payload.note||'';noteWrap.hidden=!payload.note;
      const links=modal.querySelector('[data-wz-modal-links]');links.replaceChildren();
      [['Zum Termin',payload.url,true],['Zum Wettbewerb',payload.internalUrl,false],['Offizielle Website ↗',payload.externalUrl,true]].forEach(x=>{const a=link(x[0],x[1],x[2]);if(a)links.appendChild(a);});
      hidePreview();modal.hidden=false;document.documentElement.style.overflow='hidden';modal.querySelector('.wz-modal__close').focus();
    }
    function closeModal(){if(!modal||modal.hidden)return;modal.hidden=true;document.documentElement.style.overflow='';if(lastFocus&&lastFocus.focus)lastFocus.focus();}

    function placePreview(anchor){
      if(!preview||preview.hidden)return;
      const r=anchor.getBoundingClientRect();
      const p=preview.getBoundingClientRect();
      let left=r.left+r.width/2-p.width/2;
      let top=r.top-p.height-10;
      left=clamp(left,10,window.innerWidth-p.width-10);
      if(top<10)top=Math.min(window.innerHeight-p.height-10,r.bottom+10);
      preview.style.left=Math.round(left)+'px';preview.style.top=Math.round(top)+'px';
    }
    function showPreview(anchor,payload){
      if(!preview||!payload)return;
      clearTimeout(previewTimer);currentPayload=payload;currentAnchor=anchor;
      preview.querySelector('[data-wz-preview-title]').textContent=payload.title||'';
      preview.querySelector('[data-wz-preview-competition]').textContent=payload.competition||'';
      preview.querySelector('[data-wz-preview-date]').textContent=payload.date||'';
      preview.hidden=false;placePreview(anchor);
      preview.dataset.anchorId=anchor.dataset.wzEvent||'';
    }
    function scheduleHidePreview(){clearTimeout(previewTimer);previewTimer=setTimeout(()=>{if(preview&&!preview.matches(':hover'))hidePreview();},120);}
    function hidePreview(){clearTimeout(previewTimer);if(preview){preview.hidden=true;preview.style.left='';preview.style.top='';preview.dataset.anchorId='';}currentAnchor=null;}

    if(preview){
      preview.addEventListener('mouseenter',()=>clearTimeout(previewTimer));
      preview.addEventListener('mouseleave',()=>{if(!coarse)hidePreview();});
      const more=preview.querySelector('[data-wz-more]');if(more)more.addEventListener('click',()=>openModal(currentPayload,currentAnchor||document.activeElement));
    }
    if(modal)modal.querySelectorAll('[data-wz-modal-close]').forEach(x=>x.addEventListener('click',closeModal));

    document.addEventListener('keydown',e=>{
      if(e.key==='Escape'){if(modal&&!modal.hidden)closeModal();else hidePreview();}
      if(e.key==='Tab'&&modal&&!modal.hidden){
        const focusable=Array.from(modal.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled])')).filter(x=>x.offsetParent!==null);
        if(focusable.length){const first=focusable[0],last=focusable[focusable.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}
      }
    });
    document.addEventListener('click',e=>{if(coarse&&preview&&!preview.hidden&&!preview.contains(e.target)&&!e.target.closest('[data-wz-event]'))hidePreview();});

    root.querySelectorAll('[data-wz-filter-subject],[data-wz-filter-grade],[data-wz-filter-type]').forEach(x=>x.addEventListener('change',()=>{hidePreview();render();}));
    root.querySelectorAll('[data-wz-grades]').forEach(button=>button.addEventListener('click',()=>{
      const wanted=(button.dataset.wzGrades||'').split(',').filter(Boolean);
      root.querySelectorAll('[data-wz-filter-grade]').forEach(cb=>{cb.checked=wanted.includes(cb.value);});
      hidePreview();render();
    }));
    const yearSelect=root.querySelector('[data-wz-school-year]');
    if(yearSelect)yearSelect.addEventListener('change',()=>{const url=new URL(window.location.href);url.searchParams.set('wz_schuljahr',yearSelect.value);window.location.assign(url.toString());});

    function restoreCenter(pct){
      const visible=visibleTrackWidth();
      const width=trackWidth();
      const max=Math.max(0,width-visible);
      scroller.scrollLeft=clamp(pct*width-visible/2,0,max);
      updateScrollClass();
    }
    function setRange(months){
      const anchor=centerPercent();
      rangeMonths=Number(months)||12;
      hidePreview();render({anchor:anchor});
    }
    if(rangeSelect)rangeSelect.addEventListener('change',()=>setRange(rangeSelect.value));

    function updateViewButtons(){
      root.querySelectorAll('[data-wz-view]').forEach(button=>{
        const active=button.dataset.wzView===viewMode;
        button.classList.toggle('is-active',active);button.setAttribute('aria-pressed',active?'true':'false');
      });
      if(rangeSelect)rangeSelect.disabled=viewMode!=='timeline';
      const todayButton=root.querySelector('[data-wz-today]');if(todayButton)todayButton.hidden=viewMode!=='timeline';
    }
    root.querySelectorAll('[data-wz-view]').forEach(button=>button.addEventListener('click',()=>{
      viewMode=button.dataset.wzView==='list'?'list':'timeline';hidePreview();render({restore:false});
      if(viewMode==='timeline'&&config.today)requestAnimationFrame(()=>scrollToPercent(percent(config.today)));
    }));

    const todayButton=root.querySelector('[data-wz-today]');if(todayButton&&config.today)todayButton.addEventListener('click',()=>scrollToPercent(percent(config.today)));
    function scrollToPercent(pct){
      const visible=visibleTrackWidth();
      const width=trackWidth();
      const max=Math.max(0,width-visible);
      scroller.scrollTo({left:clamp((pct/100)*width-visible/2,0,max),behavior:reducedMotion()?'auto':'smooth'});
    }
    function updateScrollClass(){root.classList.toggle('is-scrolled',scroller.scrollLeft>4);}
    scroller.addEventListener('scroll',()=>{updateScrollClass();hidePreview();},{passive:true});

    let resizeTimer=null;
    window.addEventListener('resize',()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>{hidePreview();render();},120);});

    render({restore:false});
    requestAnimationFrame(()=>{
      if(config.today)scrollToPercent(percent(config.today));
      else scroller.scrollLeft=0;
    });
  }

  document.querySelectorAll('[data-wz-timeline]').forEach(init);
})();