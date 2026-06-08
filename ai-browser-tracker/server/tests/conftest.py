import sys
import pytest
from unittest.mock import MagicMock, patch

@pytest.fixture
def in_memory_db():
    from hybrid_db import HybridDBManager
    config = {'duckdb_path': ':memory:', 'zvec_path': '/tmp/test_zvec', 'neuG_path': '/tmp/test_neug.db'}
    db = HybridDBManager(config)

    mock_collection = MagicMock()
    mock_collection.query.return_value = []
    mock_collection.insert.return_value = None
    mock_collection.flush.return_value = None
    mock_collection.num_entities = 0

    mock_neug_instance = MagicMock()
    mock_neug_instance.query_neighbors.return_value = []
    mock_neug_instance.node_count.return_value = 0
    mock_neug_instance.edge_count.return_value = 0

    with patch('hybrid_db.zvec') as mock_zvec, patch('hybrid_db.neug') as mock_neug:
        mock_zvec.create_and_open.return_value = mock_collection
        mock_neug.Graph.return_value = mock_neug_instance
        mock_zvec.CollectionSchema.return_value = MagicMock()
        mock_zvec.DataType.VECTOR_FP32 = 'VECTOR_FP32'
        mock_zvec.VectorSchema.return_value = MagicMock()

        mock_model = MagicMock()
        mock_model.encode.return_value = [__import__('numpy').zeros(384, dtype=__import__('numpy').float32)]
        db._embedding_model = mock_model

        db.init()
        db.collection = mock_collection
        db.neug = mock_neug_instance
        return db

@pytest.fixture
def client(in_memory_db):
    import server
    original_db = server.hybrid_db
    server.hybrid_db = in_memory_db
    with patch('server.ask_ollama', return_value='Test AI response'):
        with server.app.test_client() as test_client:
            yield test_client
    server.hybrid_db = original_db
