// A source-backed JSON tree. Numeric tokens are never converted to JS Number.
// Only the currently displayed children become DOM nodes.
export function jsonDocument(source) {
    JSON.parse(source); // Validate the whole wire document before exposing spans.
    const ws = i => { while (/\s/.test(source[i] || '') && i < source.length) i++; return i; };
    function stringEnd(i) { for (++i; i < source.length; i++) { if (source[i] === '\\') i++; else if (source[i] === '"') return i + 1; } throw new Error('Invalid JSON string'); }
    function nodeAt(i) {
        i=ws(i); const start=i, c=source[i]; let end;
        if(c==='"') end=stringEnd(i);
        else if(c==='{'||c==='[') {
            let depth=1; end=i+1;
            while(depth) { const ch=source[end]; if(ch==='"') {end=stringEnd(end);continue;} if(ch==='{'||ch==='[')depth++;else if(ch==='}'||ch===']')depth--;end++; }
        } else {end=i;while(end<source.length&&!/[\s,}\]]/.test(source[end]))end++;}
        return {start,end,type:c==='{'?'object':c==='['?'array':c==='"'?'string':c==='n'?'null':c==='t'||c==='f'?'boolean':'number'};
    }
    function* children(node) {
        if(!['object','array'].includes(node.type))return;
        let i=ws(node.start+1), index=0;
        while(i<node.end-1) {
            let key=String(index++);
            if(node.type==='object'){const end=stringEnd(i);key=JSON.parse(source.slice(i,end));i=ws(ws(end)+1);}
            const child=nodeAt(i);yield {key,node:child};i=ws(child.end);if(source[i]!==',')break;i=ws(i+1);
        }
    }
    const raw=node=>source.slice(node.start,node.end);
    const root=nodeAt(0);
    const member=(node,key)=>{for(const entry of children(node))if(entry.key===key)return entry.node;return null;};
    return {root,children,raw,member};
}
export const pointer = path => path.length ? '/'+path.map(k=>k.replaceAll('~','~0').replaceAll('/','~1')).join('/') : '';
export function* searchJson(doc,node,needle,{limit=20000}={}) {
    const query=needle.toLowerCase();let visited=0;
    function* walk(n,path) {
        if(++visited>limit)return;
        const leaf=!['array','object'].includes(n.type);
        const value=leaf?(n.type==='string'?JSON.parse(doc.raw(n)):doc.raw(n)):'';
        if(path.at(-1)?.toLowerCase().includes(query)||(leaf&&String(value).toLowerCase().includes(query)))yield {path,node:n};
        if(!leaf)for(const child of doc.children(n)){if(visited>=limit){visited=limit+1;break;}yield* walk(child.node,[...path,child.key]);}
    }
    yield* walk(node,[]);return visited>limit;
}
// Compact/pretty representation uses wire tokens, not JSON.stringify(parsed numbers).
export function prettyJson(doc,node,max=262144) {
    if(node.end-node.start>max)return null;
    let size=0;const parts=[];
    const add=s=>{size+=s.length;if(size>max)throw new RangeError('pretty budget');parts.push(s);};
    function walk(n,depth){if(!['array','object'].includes(n.type)){add(doc.raw(n));return;}const object=n.type==='object';add(object?'{':'[');let first=true;for(const c of doc.children(n)){add((first?'':',')+'\n'+'  '.repeat(depth+1));if(object)add(JSON.stringify(c.key)+': ');walk(c.node,depth+1);first=false;}if(!first)add('\n'+'  '.repeat(depth));add(object?'}':']');}
    try{walk(node,0);return parts.join('');}catch(e){if(e instanceof RangeError)return null;throw e;}
}
