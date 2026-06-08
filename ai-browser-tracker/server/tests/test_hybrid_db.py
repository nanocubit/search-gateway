def test_save_page_visit(in_memory_db):
    in_memory_db.save_page_visit('http://example.com', 'Example')
    visits = in_memory_db.get_page_visits()
    assert len(visits) == 1

def test_save_chat_message(in_memory_db):
    msg_id = in_memory_db.save_chat_message('chatgpt', 'user', 'Hello', 'http://chat.openai.com')
    assert msg_id is not None
    msgs = in_memory_db.get_chat_messages(platform='chatgpt')
    assert len(msgs) == 1

def test_dedup(in_memory_db):
    in_memory_db.save_page_visit('http://test.com', 'Test')
    in_memory_db.save_page_visit('http://test.com', 'Test')
    assert len(in_memory_db.get_page_visits()) == 1
