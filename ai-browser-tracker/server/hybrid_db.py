import duckdb
import numpy as np
import hashlib
import datetime
import uuid
import os
from typing import Dict, List, Any, Optional
from sentence_transformers import SentenceTransformer


class HybridDBManager:
    def __init__(self, config: Dict[str, Any]):
        self.config = config
        self.duckdb_path = config['duckdb_path']
        self.zvec_path = config['zvec_path']
        self.neug_path = config['neuG_path']
        self._embedding_model = None
        self.conn = None
        self.collection = None
        self.neug = None

    @property
    def embedding_model(self):
        if self._embedding_model is None:
            self._embedding_model = SentenceTransformer('all-MiniLM-L6-v2')
        return self._embedding_model

    def init(self):
        try:
            self.conn = duckdb.connect(self.duckdb_path)
            self._init_duckdb_schema()
        except duckdb.Error as e:
            raise RuntimeError(f"Failed to init DuckDB: {e}")
        try:
            os.makedirs(self.zvec_path, exist_ok=True)
            import zvec
            schema = zvec.CollectionSchema(name="messages", vectors=[zvec.VectorSchema("embedding", zvec.DataType.VECTOR_FP32, 384)])
            self.collection = zvec.create_and_open(path=self.zvec_path, schema=schema)
        except Exception as e:
            raise RuntimeError(f"Failed to init Zvec: {e}")
        try:
            os.makedirs(os.path.dirname(self.neug_path), exist_ok=True)
            import neug
            self.neug = neug.Graph(self.neug_path)
            self._init_neug_schema()
        except Exception as e:
            raise RuntimeError(f"Failed to init NeuG: {e}")

    def _init_duckdb_schema(self):
        self.conn.execute("""
            CREATE TABLE IF NOT EXISTS page_visits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url VARCHAR, title VARCHAR,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                hash VARCHAR UNIQUE
            )
        """)
        self.conn.execute("""
            CREATE TABLE IF NOT EXISTS chat_messages (
                id VARCHAR PRIMARY KEY,
                platform VARCHAR, role VARCHAR, content TEXT,
                url VARCHAR, timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                hash VARCHAR UNIQUE, embedding_id VARCHAR, agent_name VARCHAR,
                dwell_time_sec INTEGER DEFAULT 0,
                attention_score FLOAT DEFAULT 0.0,
                was_viewed BOOLEAN DEFAULT TRUE
            )
        """)
        self.conn.execute("""
            CREATE TABLE IF NOT EXISTS network_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                method VARCHAR, url VARCHAR,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)
        self.conn.execute("""
            CREATE TABLE IF NOT EXISTS agent_sessions (
                agent_id VARCHAR PRIMARY KEY,
                agent_name VARCHAR, agent_type VARCHAR,
                url VARCHAR, status VARCHAR,
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)
        self.conn.execute("CREATE INDEX IF NOT EXISTS idx_chat_platform ON chat_messages(platform)")
        self.conn.execute("CREATE INDEX IF NOT EXISTS idx_chat_timestamp ON chat_messages(timestamp)")
        self.conn.execute("CREATE INDEX IF NOT EXISTS idx_agent_name ON agent_sessions(agent_name)")

    def _init_neug_schema(self):
        self.neug.create_schema("message", {"content": str, "platform": str, "role": str, "timestamp": str, "attention": str})
        self.neug.create_schema("agent", {"type": str, "url": str, "status": str, "last_seen": str})
        self.neug.create_schema("session", {"start_time": str, "end_time": str})
        self.neug.create_relation("sent_message", "agent", "message")
        self.neug.create_relation("part_of_session", "agent", "session")
        self.neug.create_relation("similar_to", "message", "message")
        self.neug.create_relation("follows", "message", "message")

    def create_embedding(self, text: str) -> np.ndarray:
        return self.embedding_model.encode([text])[0].astype(np.float32)

    def save_page_visit(self, url: str, title: str):
        hash_val = hashlib.sha256(f"{url}:{title}".encode()).hexdigest()
        try:
            self.conn.execute("INSERT INTO page_visits (url, title, hash) VALUES (?, ?, ?)", [url, title, hash_val])
            self.conn.commit()
        except duckdb.ConstraintException:
            pass

    def save_chat_message(self, platform: str, role: str, content: str, url: str, **kwargs) -> Optional[str]:
        message_id = str(uuid.uuid4())
        hash_val = hashlib.sha256(f"{content}:{platform}".encode()).hexdigest()
        dwell_time_sec = int(kwargs.get('dwell_time_ms', 0) / 1000)
        was_viewed = kwargs.get('was_viewed', True)
        attention_score = min(1.0, dwell_time_sec / 60.0)
        try:
            self.conn.execute(
                "INSERT INTO chat_messages (id, platform, role, content, url, hash, dwell_time_sec, attention_score, was_viewed) VALUES (?,?,?,?,?,?,?,?,?)",
                [message_id, platform, role, content, url, hash_val, dwell_time_sec, attention_score, was_viewed]
            )
            self.conn.commit()
        except duckdb.ConstraintException:
            return None

        emb = self.create_embedding(content)
        import zvec
        doc = zvec.Doc(id=message_id, vectors={"embedding": emb.tolist()})
        self.collection.insert([doc])
        self.collection.flush()

        import neug
        self.neug.add_node("message", f"msg:{message_id}", {
            "content": content[:500], "platform": platform, "role": role,
            "timestamp": datetime.datetime.now().isoformat(),
            "attention": str(attention_score), "viewed": str(was_viewed)
        })
        sim_results = self.collection.query(zvec.VectorQuery("embedding", vector=emb.tolist(), topk=3))
        for r in sim_results:
            if r.id != message_id:
                self.neug.add_edge("similar_to", f"msg:{r.id}", f"msg:{message_id}", {"score": float(r.score)})
        last = self.conn.execute(
            "SELECT id FROM chat_messages WHERE platform=? AND timestamp < CURRENT_TIMESTAMP ORDER BY timestamp DESC LIMIT 1", [platform]
        ).fetchone()
        if last:
            self.neug.add_edge("follows", f"msg:{last[0]}", f"msg:{message_id}", {})
        self.neug.persist()
        return message_id

    def save_network_request(self, method: str, url: str):
        self.conn.execute("INSERT INTO network_requests (method, url) VALUES (?, ?)", [method, url])
        self.conn.commit()

    def register_agent(self, name: str, agent_type: str, url: str) -> str:
        agent_id = str(uuid.uuid4())
        self.conn.execute("INSERT INTO agent_sessions (agent_id, agent_name, agent_type, url, status) VALUES (?,?,?,?,'active')", [agent_id, name, agent_type, url])
        self.conn.commit()
        import neug
        self.neug.add_node("agent", f"agent:{agent_id}", {"type": agent_type, "url": url, "status": "active", "last_seen": datetime.datetime.now().isoformat()})
        self.neug.persist()
        return agent_id

    def get_chat_messages(self, platform=None, role=None, limit=50, start_date=None, agent_name=None):
        query = "SELECT id, platform, role, content, url, timestamp, embedding_id FROM chat_messages"
        conds, params = [], []
        if platform: conds.append("platform=?"); params.append(platform)
        if role: conds.append("role=?"); params.append(role)
        if start_date: conds.append("timestamp >= ?"); params.append(start_date)
        if agent_name: conds.append("agent_name=?"); params.append(agent_name)
        if conds: query += " WHERE " + " AND ".join(conds)
        query += " ORDER BY timestamp DESC LIMIT ?"; params.append(limit)
        rows = self.conn.execute(query, params).fetchall()
        return [{"id": r[0], "platform": r[1], "role": r[2], "content": r[3], "url": r[4],
                 "timestamp": r[5].isoformat() if r[5] else None, "embedding_id": r[6]} for r in rows]

    def get_page_visits(self, limit=50):
        rows = self.conn.execute("SELECT id, url, title, timestamp FROM page_visits ORDER BY timestamp DESC LIMIT ?", [limit]).fetchall()
        return [{"id": r[0], "url": r[1], "title": r[2], "timestamp": r[3].isoformat() if r[3] else None} for r in rows]

    def search_similar(self, query: str, limit=10, platform=None):
        emb = self.create_embedding(query)
        import zvec
        results = self.collection.query(zvec.VectorQuery("embedding", vector=emb.tolist(), topk=limit * 2))
        output = []
        for r in results:
            msg_id = r.id
            if platform:
                row = self.conn.execute("SELECT platform FROM chat_messages WHERE id=?", [msg_id]).fetchone()
                if not row or row[0] != platform: continue
            row = self.conn.execute("SELECT id, platform, role, content, url, timestamp, attention_score, was_viewed FROM chat_messages WHERE id=?", [msg_id]).fetchone()
            if row:
                semantic_score = float(r.score)
                attention_score = row[6] or 0.0
                final_score = semantic_score * (0.8 + attention_score * 0.4)
                output.append({
                    "message_id": row[0], "content": row[3], "platform": row[1], "role": row[2],
                    "url": row[4], "timestamp": row[5].isoformat() if row[5] else None,
                    "semantic_score": semantic_score, "attention_score": attention_score,
                    "was_viewed": row[7], "score": final_score
                })
                if len(output) >= limit: break
        return output

    def hybrid_search(self, query: str, limit=10, platform=None):
        fts_results = {}
        try:
            search_pattern = f"%{query}%"
            fts_query = self.conn.execute("""
                SELECT id, platform, role, content, url, timestamp, attention_score, was_viewed
                FROM chat_messages WHERE content ILIKE ?
                ORDER BY timestamp DESC LIMIT ?
            """, [search_pattern, limit * 2]).fetchall()
            for row in fts_query:
                fts_results[row[0]] = {
                    "id": row[0], "platform": row[1], "role": row[2], "content": row[3],
                    "url": row[4], "timestamp": row[5].isoformat() if row[5] else None,
                    "attention_score": row[6] or 0.0, "was_viewed": row[7]
                }
        except Exception as e:
            print(f"[HybridSearch] ILIKE error: {e}")

        vector_results = {}
        try:
            emb = self.create_embedding(query)
            import zvec
            vec_raw = self.collection.query(zvec.VectorQuery("embedding", vector=emb.tolist(), topk=limit * 2))
            for r in vec_raw:
                row = self.conn.execute("SELECT id, platform, role, content, url, timestamp, attention_score, was_viewed FROM chat_messages WHERE id=?", [r.id]).fetchone()
                if row:
                    vector_results[row[0]] = {
                        "id": row[0], "platform": row[1], "role": row[2], "content": row[3],
                        "url": row[4], "timestamp": row[5].isoformat() if row[5] else None,
                        "attention_score": row[6] or 0.0, "was_viewed": row[7],
                        "vector_score": float(r.score)
                    }
        except Exception as e:
            print(f"[HybridSearch] Vector error: {e}")

        all_ids = set(fts_results.keys()) | set(vector_results.keys())
        combined = []
        for msg_id in all_ids:
            fts = fts_results.get(msg_id)
            vec = vector_results.get(msg_id)
            score = 0.0
            if fts: score += 0.3
            if vec: score += 0.7 * vec['vector_score']
            if fts and vec: score *= 1.2
            source = fts or vec
            if source:
                attention = source.get('attention_score', 0.0)
                score *= (0.8 + attention * 0.4)
            combined.append({
                "message_id": msg_id, "content": source['content'], "platform": source['platform'],
                "role": source['role'], "url": source['url'], "timestamp": source['timestamp'],
                "score": score, "attention_score": attention if source else 0.0
            })
        combined.sort(key=lambda x: x['score'], reverse=True)
        return combined[:limit]

    def get_graph_context(self, message_id, depth=2):
        node_id = f"msg:{message_id}"
        import neug
        related = self.neug.query_neighbors(node_id, depth=depth)
        msgs, agents = [], []
        for n in related:
            if hasattr(n, 'node_type'):
                if n.node_type == "message":
                    id_ = n.node_id.replace('msg:', '')
                    row = self.conn.execute("SELECT id, platform, role, content, timestamp FROM chat_messages WHERE id=?", [id_]).fetchone()
                    if row:
                        msgs.append({"id": row[0], "platform": row[1], "role": row[2], "content": row[3][:200], "timestamp": row[4].isoformat() if row[4] else None})
                elif n.node_type == "agent":
                    agents.append({"agent_id": n.node_id.replace('agent:', ''), "type": n.properties.get('type'), "url": n.properties.get('url')})
        return {"related_messages": msgs, "agent_connections": agents, "depth": depth}

    def get_agent_sessions(self, agent_name=None):
        if agent_name:
            rows = self.conn.execute("SELECT agent_id, agent_name, agent_type, url, status, last_seen FROM agent_sessions WHERE agent_name=? ORDER BY last_seen DESC", [agent_name]).fetchall()
        else:
            rows = self.conn.execute("SELECT agent_id, agent_name, agent_type, url, status, last_seen FROM agent_sessions ORDER BY last_seen DESC LIMIT 100").fetchall()
        return [{"agent_id": r[0], "agent_name": r[1], "agent_type": r[2], "url": r[3], "status": r[4], "last_seen": r[5].isoformat() if r[5] else None} for r in rows]

    def get_stats(self):
        try:
            duckdb_stats = {
                "page_visits": self.conn.execute("SELECT COUNT(*) FROM page_visits").fetchone()[0],
                "chat_messages": self.conn.execute("SELECT COUNT(*) FROM chat_messages").fetchone()[0],
                "network_requests": self.conn.execute("SELECT COUNT(*) FROM network_requests").fetchone()[0],
                "agent_sessions": self.conn.execute("SELECT COUNT(*) FROM agent_sessions").fetchone()[0],
            }
        except Exception:
            duckdb_stats = {"page_visits": 0, "chat_messages": 0, "network_requests": 0, "agent_sessions": 0}
        zvec_stats = {"total_vectors": 0, "dimension": 384, "metric": "COSINE"}
        if self.collection:
            try: zvec_stats["total_vectors"] = self.collection.num_entities
            except Exception: pass
        neug_stats = {"total_nodes": 0, "total_edges": 0}
        if self.neug:
            try:
                neug_stats["total_nodes"] = self.neug.node_count()
                neug_stats["total_edges"] = self.neug.edge_count()
            except Exception: pass
        return {"duckdb": duckdb_stats, "zvec": zvec_stats, "neug": neug_stats}
